<?php

namespace Core\Handler;

use Core\Entity\AppParameters;
use Core\Exception\SecurityException;

/**
 * Class SecurityHandler
 * @package Core\Service
 */
class SecurityHandler
{
    public const ENCRYPT_METHOD = "AES-256-CBC";

    /** @var AppParameters */
    protected $appParameters;

    /**
     * SecurityHandler constructor.
     *
     * @param AppParameters $appParameters
     */
    public function __construct(AppParameters $appParameters)
    {
        $this->appParameters = $appParameters;
    }

    public function checkSingleDomain(string $imageSource): string
    {
        if ($this->appParameters->parameterByKey('single_domain')) {
            $basePath = $this->appParameters->parameterByKey('single_domain_base_path');
            return trim($basePath, '/') . '/' . trim($imageSource, '/');
        }
        return $imageSource;
    }

    /**
     * Check Restricted Domain enabled
     *
     * @param string $imageSource
     *
     * @throws SecurityException
     */
    public function checkRestrictedDomains(string $imageSource)
    {
        if (
            $this->appParameters->parameterByKey('restricted_domains') &&
            is_array($this->appParameters->parameterByKey('whitelist_domains')) &&
            !in_array(parse_url($imageSource, PHP_URL_HOST), $this->appParameters->parameterByKey('whitelist_domains'))
        ) {
            throw  new SecurityException(
                'Restricted domains enabled, the domain your fetching from is not allowed: ' .
                    parse_url($imageSource, PHP_URL_HOST)
            );
        }
    }

    /**
     * @param string $options
     * @param string $imageSrc
     *
     * @return array
     * @throws SecurityException
     */
    public function checkSecurityHash(string $options, string $imageSrc): array
    {
        if (empty($this->appParameters->parameterByKey('security_key'))) {
            return [$options, $imageSrc];
        }

        if (empty($this->appParameters->parameterByKey('security_iv'))) {
            throw  new SecurityException(
                'Security iv is not set in parameters.yml (security_iv)'
            );
        }

        $decryptedHash = $this->decrypt($options);

        if (empty($decryptedHash)) {
            throw  new SecurityException(
                "Security Key enabled: Requested URL doesn't match with the hashed Security key !"
            );
        };

        [$explodedOptions, $explodedImageSrc] = explode('/', $decryptedHash, 2);

        if (empty($explodedImageSrc) || empty($explodedOptions)) {
            throw  new SecurityException(
                "Something went wrong when decrypting the hashed URL: " .
                    $options
            );
        }

        return [$explodedOptions, $explodedImageSrc];
    }

    /**
     * @param $string
     *
     * @return string
     */
    public function encrypt(string $string): string
    {
        [$secretKey, $secretIv] = $this->createHash();
        $output = base64_encode(openssl_encrypt($string, self::ENCRYPT_METHOD, $secretKey, 0, $secretIv));

        return $output;
    }

    /**
     * @param $string
     *
     * @return string
     */
    public function decrypt(string $string): string
    {
        [$secretKey, $secretIv] = $this->createHash();
        $output = openssl_decrypt(base64_decode($string), self::ENCRYPT_METHOD, $secretKey, 0, $secretIv);

        return $output;
    }

    /**
     * @return array
     * @throws SecurityException
     */
    protected function createHash(): array
    {
        $secretKey = $this->appParameters->parameterByKey('security_key');
        $secretIv = $this->appParameters->parameterByKey('security_iv');

        if (empty($secretKey)) {
            throw  new SecurityException(
                "security_key in empty im parameters.yml!"
            );
        }
        // hash
        $secretKey = hash('sha256', $secretKey);

        //initialization vector(IV) - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
        $secretIv = substr(hash('sha256', $secretIv), 0, 16);

        return [$secretKey, $secretIv];
    }
}
