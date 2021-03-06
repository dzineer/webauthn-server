<?php

namespace MadWizard\WebAuthn\Attestation\Verifier;

use MadWizard\WebAuthn\Attestation\AttestationType;
use MadWizard\WebAuthn\Attestation\AuthenticatorData;
use MadWizard\WebAuthn\Attestation\Registry\AttestationFormatInterface;
use MadWizard\WebAuthn\Attestation\Registry\BuiltInAttestationFormat;
use MadWizard\WebAuthn\Attestation\Statement\AttestationStatementInterface;
use MadWizard\WebAuthn\Attestation\Statement\NoneAttestationStatement;
use MadWizard\WebAuthn\Attestation\TrustPath\EmptyTrustPath;
use MadWizard\WebAuthn\Exception\VerificationException;

final class NoneAttestationVerifier implements AttestationVerifierInterface
{
    public function verify(AttestationStatementInterface $attStmt, AuthenticatorData $authenticatorData, string $clientDataHash): VerificationResult
    {
        if (!($attStmt instanceof NoneAttestationStatement)) {
            throw new VerificationException('Expecting NoneAttestationStatement.');
        }
        return new VerificationResult(AttestationType::NONE, new EmptyTrustPath());
    }

    public function getSupportedFormat(): AttestationFormatInterface
    {
        return new BuiltInAttestationFormat(
            NoneAttestationStatement::FORMAT_ID,
            NoneAttestationStatement::class,
            $this
        );
    }
}
