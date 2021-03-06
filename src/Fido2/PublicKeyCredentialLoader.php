<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014-2018 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace U2FAuthentication\Fido2;

use Assert\Assertion;
use Base64Url\Base64Url;
use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use U2FAuthentication\Fido2\AttestationStatement\AttestationObjectLoader;
use U2FAuthentication\Fido2\AuthenticationExtensions\AuthenticationExtensionsClientOutputsLoader;

class PublicKeyCredentialLoader
{
    private $attestationObjectLoader;
    private $decoder;

    public function __construct(AttestationObjectLoader $attestationObjectLoader, Decoder $decoder)
    {
        $this->attestationObjectLoader = $attestationObjectLoader;
        $this->decoder = $decoder;
    }

    public function load(string $data): PublicKeyCredential
    {
        $json = \Safe\json_decode($data, true);
        Assertion::keyExists($json, 'id');
        Assertion::keyExists($json, 'rawId');
        Assertion::keyExists($json, 'response');

        $id = Base64Url::decode($json['id']);
        $rawId = Base64Url::decode($json['rawId']);
        Assertion::true(hash_equals($id, $rawId));

        $publicKeyCredential = new PublicKeyCredential(
            $json['id'],
            $json['type'] ?? 'public-key',
            $rawId,
            $this->createResponse($json['response'])
        );

        return $publicKeyCredential;
    }

    private function createResponse(array $response): AuthenticatorResponse
    {
        Assertion::keyExists($response, 'clientDataJSON');
        switch (true) {
            case array_key_exists('attestationObject', $response):
                $attestationObject = $this->attestationObjectLoader->load($response['attestationObject']);

                return new AuthenticatorAttestationResponse(CollectedClientData::createFormJson($response['clientDataJSON']), $attestationObject);
            case array_key_exists('authenticatorData', $response) && array_key_exists('signature', $response):
                $authData = Base64Url::decode($response['authenticatorData']);

                $authDataStream = new StringStream($authData);
                $rp_id_hash = $authDataStream->read(32);
                $flags = $authDataStream->read(1);
                $signCount = $authDataStream->read(4);
                $signCount = unpack('N', $signCount)[1];

                $attestedCredentialData = null;
                if (\ord($flags) & 0b01000000) {
                    $aaguid = $authDataStream->read(16);
                    $credentialLength = $authDataStream->read(2);
                    $credentialLength = unpack('n', $credentialLength)[1];
                    $credentialId = $authDataStream->read($credentialLength);
                    $credentialPublicKey = $this->decoder->decode($authDataStream);
                    Assertion::isInstanceOf($credentialPublicKey, MapObject::class, 'The data does not contain a valid credential public key.');
                    $attestedCredentialData = new AttestedCredentialData($aaguid, $credentialId, (string) $credentialPublicKey);
                }

                $extension = null;
                if (\ord($flags) & 0b10000000) {
                    $extension = $this->decoder->decode($authDataStream);
                    $extension = AuthenticationExtensionsClientOutputsLoader::load($extension);
                }
                $authenticatorData = new AuthenticatorData($authData, $rp_id_hash, $flags, $signCount, $attestedCredentialData, $extension);

                return new AuthenticatorAssertionResponse(
                    CollectedClientData::createFormJson($response['clientDataJSON']),
                    $authenticatorData,
                    Base64Url::decode($response['signature']),
                    $response['userHandle'] ?? null
                );
            default:
                throw new \InvalidArgumentException();
        }
    }
}
