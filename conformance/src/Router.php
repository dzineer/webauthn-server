<?php

namespace MadWizard\WebAuthn\Conformance;

use MadWizard\WebAuthn\Builder\ServerBuilder;
use MadWizard\WebAuthn\Config\RelyingParty;
use MadWizard\WebAuthn\Credential\CredentialStoreInterface;
use MadWizard\WebAuthn\Credential\UserHandle;
use MadWizard\WebAuthn\Dom\ResidentKeyRequirement;
use MadWizard\WebAuthn\Exception\WebAuthnException;
use MadWizard\WebAuthn\Extension\Generic\GenericExtension;
use MadWizard\WebAuthn\Extension\Generic\GenericExtensionInput;
use MadWizard\WebAuthn\Json\JsonConverter;
use MadWizard\WebAuthn\Metadata\Source\MetadataServiceSource;
use MadWizard\WebAuthn\Metadata\Source\StatementDirectorySource;
use MadWizard\WebAuthn\Policy\Policy;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationContext;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationOptions;
use MadWizard\WebAuthn\Server\Registration\RegistrationContext;
use MadWizard\WebAuthn\Server\Registration\RegistrationOptions;
use MadWizard\WebAuthn\Server\ServerInterface;
use MadWizard\WebAuthn\Server\UserIdentity;
use RuntimeException;
use Throwable;

class Router
{
    /**
     * @var ServerInterface
     */
    private $server;

    /**
     * @var CredentialStoreInterface
     */
    private $store;

    /**
     * @var string
     */
    private $varDir;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var int|null
     */
    private $debugIdx;

    /**
     * @var ErrorLogger
     */
    private $logger;

    public function __construct(string $metadataDir, string $varDir)
    {
        $this->store = new TestCredentialStore();
        $this->logger = new ErrorLogger();
        $this->server = $this->createServer($metadataDir);
        $this->varDir = $varDir;
        $this->debug = (bool) ($_ENV['DEBUG'] ?? false);
    }

    private function createServer(string $metadataDir): ServerInterface
    {
        $builder = new ServerBuilder();

        $rp = new RelyingParty('Test server', 'http://' . $_SERVER['HTTP_HOST']);

        $root = $_ENV['MDS_ROOT'] ?? null;
        if ($root === null) {
            throw new RuntimeException('Missing MDS_ROOT env setting.');
        }

        $builder
            ->setRelyingParty($rp)
            ->configurePolicy(
                function (Policy $policy) {
                    // Conformance tools do not require user presence
                    // see https://github.com/fido-alliance/conformance-tools-issues/issues/434
                    $policy->setUserPresenceRequired(false);
                }
            )
            ->enableCrl(true)
            ->setLogger($this->logger)
            ->trustWithoutMetadata(false)
            ->strictSupportedFormats(true)
            ->addCustomExtension(new GenericExtension('example.extension'))
            ->setCredentialStore($this->store)
            ->setCacheDirectory(__DIR__ . '/../../var/conformance');

        for ($i = 0; $i < 10; $i++) {
            $url = $_ENV['MDS_URL' . $i] ?? null;
            if ($url) {
                $builder->addMetadataSource(new MetadataServiceSource($url, $root));
            }
        }

        $builder->addMetadataSource(new StatementDirectorySource(__DIR__ . '/../metadata'));
        return $builder->build();
    }

    private function getPostJson(string $postData): array
    {
        $json = json_decode($postData, true, 10);
        if ($json === null) {
            throw new StatusException('Invalid JSON posted');
        }

        return $json;
    }

    public function run(string $url): void
    {
        try {
            $this->debugIdx = null;
            $postData = file_get_contents('php://input');
            error_log($url);
            //error_log("  In:  " . $postData);
            $response = $this->getResponse($url, $postData);
        } catch (StatusException $e) {
            $prefix = $this->debugIdx === null ? '' : ($this->debugIdx . ' ');
            $response = [500, ['status' => 'failed', 'errorMessage' => $prefix . $e->getMessage()]];
        } catch (WebAuthnException $e) {
            $prefix = $this->debugIdx === null ? '' : ($this->debugIdx . ' ');
            $suffix = $this->debug ? (PHP_EOL . $e->getTraceAsString()) : '';
            $response = [400, ['status' => 'failed', 'errorMessage' => $prefix . $e->getMessage() . $suffix]];
        } catch (Throwable $e) {
            $prefix = $this->debugIdx === null ? '' : ($this->debugIdx . ' ');
            $suffix = $this->debug ? (PHP_EOL . $e->getTraceAsString()) : '';
            $response = [500, ['status' => 'failed', 'errorMessage' => $prefix . $e->getMessage() . $suffix]];
        }

        if ($response === null) {
            $response = [404, ['status' => 'failed']];
        }

        if ($this->debugIdx !== null) {
            $response[1]['_idx'] = $this->debugIdx;
        }

        $statusCode = $response[0];
        if ($statusCode === 200) {
            $this->logger->info('Response: OK');
        } else {
            $this->logger->info(sprintf('Response: [%d] %s', $statusCode, $response[1]['errorMessage'] ?? '?'));
        }

        http_response_code($statusCode);
        header('Content-Type: application/json');
        die(json_encode($response[1], JSON_PRETTY_PRINT));
    }

    private function getResponse(string $url, string $postData): ?array
    {
        $saveReq = $this->debug;
        $serDir = $this->varDir . DIRECTORY_SEPARATOR . '/ser/';

        if (preg_match('~^/test/(\d+)$~', $url, $match)) {
            $file = $serDir . $match[1];
            if (!file_exists(($file))) {
                return null;
            }
            [$session, $url, $postData] = unserialize(file_get_contents($file));
            $_SESSION = $session;
            $saveReq = false;
        }

        if ($saveReq) {
            if (!is_dir($serDir)) {
                mkdir($serDir);
            }
            $idx = (int) ($_SESSION['x'] ?? 0);
            $idx++;
            $_SESSION['x'] = $idx;
            $this->debugIdx = $idx;
            file_put_contents($serDir . $idx, \serialize([$_SESSION, $url, $postData]));
            error_log('--- ' . $idx . ' ---');
        }

        switch ($url) {
            case '/attestation/options':
                return $this->attestationOptions($postData);
            case '/attestation/result':
                return $this->attestationResult($postData);
            case '/assertion/options':
                return $this->assertionOptions($postData);
            case '/assertion/result':
                return $this->assertionResult($postData);
        }
        return null;
    }

    public function attestationOptions(string $postData): array
    {
        $req = $this->getPostJson($postData);
        $userIdentity = new UserIdentity(
            UserHandle::fromBinary($req['username']),
            $req['username'],
            $req['displayName']
        );

        $opts = RegistrationOptions::createForUser($userIdentity);

        $att = $req['attestation'] ?? 'none';
        $opts->setAttestation($att);

        $sel = $req['authenticatorSelection'] ?? [];

        $opts->setAuthenticatorAttachment($sel['authenticatorAttachment'] ?? null);

        if (($v = $sel['requireResidentKey'] ?? null) !== null) {
            $opts->setResidentKey($v ? ResidentKeyRequirement::REQUIRED : ResidentKeyRequirement::DISCOURAGED);
        }
        $opts->setUserVerification($sel['userVerification'] ?? null);

        foreach ($req['extensions'] ?? [] as $identifier => $ext) {
            $opts->addExtensionInput(new GenericExtensionInput($identifier, $ext));
        }
        $opts->setExcludeExistingCredentials(true);
        $regReq = $this->server->startRegistration($opts);

        $challenge = $regReq->getClientOptions()->getChallenge()->getBase64Url();
        $_SESSION['context'][$challenge] = $regReq->getContext();
        return [200, array_merge(['status' => 'ok', 'errorMessage' => ''], $regReq->getClientOptionsJson())];
    }

    public function attestationResult(string $req): array
    {
        $pkc = JsonConverter::decodeAttestationString($req);
        $challenge = $pkc->getResponse()->getParsedClientData()->getChallenge();
        $context = $_SESSION['context'][$challenge] ?? null;

        if (!($context instanceof RegistrationContext)) {
            return [500, ['status' => 'error', 'errorMessage' => 'context missing']];
        }
        unset($_SESSION['context'][$challenge]);

        $this->server->finishRegistration($pkc, $context);

        return [200, ['status' => 'ok', 'errorMessage' => '']];
    }

    public function assertionOptions(string $postData): array
    {
        $req = $this->getPostJson($postData);

        $opts = AuthenticationOptions::createForUser(UserHandle::fromBinary($req['username']));
        foreach ($req['extensions'] ?? [] as $identifier => $ext) {
            $opts->addExtensionInput(new GenericExtensionInput($identifier, $ext));
        }

        $opts->setUserVerification($req['userVerification'] ?? 'preferred');

        $authReq = $this->server->startAuthentication($opts);

        $challenge = $authReq->getClientOptions()->getChallenge()->getBase64Url();

        $_SESSION['context'][$challenge] = $authReq->getContext();
        return [200, array_merge(['status' => 'ok', 'errorMessage' => ''], $authReq->getClientOptionsJson())];
    }

    public function assertionResult(string $req): array
    {
        $pkc = JsonConverter::decodeAssertionString($req);
        $challenge = $pkc->getResponse()->getParsedClientData()->getChallenge();

        $context = $_SESSION['context'][$challenge];
        if (!($context instanceof AuthenticationContext)) {
            return [500, ['status' => 'error', 'errorMessage' => 'context missing']];
        }
        unset($_SESSION['context'][$challenge]);
        $this->server->finishAuthentication($pkc, $context);

        return [200, ['status' => 'ok', 'errorMessage' => '']];
    }
}
