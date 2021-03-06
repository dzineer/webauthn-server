<?php

namespace MadWizard\WebAuthn\Attestation\Registry;

use MadWizard\WebAuthn\Attestation\AttestationObject;
use MadWizard\WebAuthn\Attestation\Statement\AttestationStatementInterface;
use MadWizard\WebAuthn\Attestation\Verifier\AttestationVerifierInterface;
use MadWizard\WebAuthn\Exception\FormatNotSupportedException;

interface AttestationFormatRegistryInterface
{
    public function createStatement(AttestationObject $attestationObject): AttestationStatementInterface;

    /**
     * @throws FormatNotSupportedException
     */
    public function getVerifier(string $formatId): AttestationVerifierInterface;
}
