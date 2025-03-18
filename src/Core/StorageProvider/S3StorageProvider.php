<?php

namespace Core\StorageProvider;

use Aws\S3\S3Client;
use Core\Exception\MissingParamsException;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Storage class to manage Storage provider from FlySystem
 *
 * Class StorageProvider
 * @package Core\Provider
 */
class S3StorageProvider implements ServiceProviderInterface
{
    /**
     * @param Container $app
     *
     * @throws MissingParamsException
     */
    public function register(Container $app)
    {
        $s3Params = $app['params']->parameterByKey('aws_s3');
        $requiredParams = ['access_id', 'secret_key', 'bucket_name', 'region'];
        $this->validateRequiredParams($s3Params, $requiredParams);

        $this->registerS3ServiceProvider($app, $s3Params);
        $app['flysystems']['file_path_resolver'] = function () use ($s3Params) {
            $pathPrefix = !empty($s3Params['path_prefix']) ? $s3Params['path_prefix'] . '/' : '';
            $pathResolver = isset($s3Params['endpoint']) && !empty($s3Params['endpoint'])
                ? sprintf($s3Params['endpoint'], $s3Params['bucket_name'], $s3Params['region'])
                . $pathPrefix . '%s'
                : sprintf('https://%s.s3.%s.amazonaws.com/', $s3Params['bucket_name'], $s3Params['region'])
                . $pathPrefix . '%s';
            return $pathResolver;
        };
    }

    /**
     * Validates that all required parameters are present in the S3 configuration.
     *
     * @param array $s3Params The S3 configuration parameters.
     * @param array $requiredParams The list of required parameter keys.
     * @throws MissingParamsException If any required parameter is missing.
     */
    public static function validateRequiredParams(array $s3Params, array $requiredParams): void
    {
        foreach ($requiredParams as $param) {
            if (!isset($s3Params[$param]) || empty($s3Params[$param])) {
                throw new MissingParamsException("Parameter '$param' is required for S3 configuration.");
            }
        }
    }

    /**
     * @param Container $app
     * @param array     $s3Params
     *
     * @return Container
     */
    protected function registerS3ServiceProvider(Container $app, array $s3Params): Container
    {
        $clientParams = [
            'credentials' => [
                'key' => $s3Params['access_id'],
                'secret' => $s3Params['secret_key'],
            ],
            'region' => $s3Params['region'],
            'version' => 'latest',
            'bucket_endpoint' => $s3Params['bucket_endpoint'] ?? false,
            'use_path_style_endpoint' => $s3Params['use_path_style_endpoint'] ?? false,
        ];

        // Support for third party S3 compatible services
        if (isset($s3Params['endpoint'])) {
            $clientParams['endpoint'] = $s3Params['endpoint'];
        }

        $app->register(
            new FlysystemServiceProvider(),
            [
                'flysystem.filesystems' => [
                    'storage_handler' => [
                        'adapter' => 'League\Flysystem\AwsS3V3\AwsS3V3Adapter',
                        'args' => [
                            new S3Client($clientParams),
                            urlencode($s3Params['bucket_name']),
                            $s3Params['path_prefix'] ?? '',
                            new PortableVisibilityConverter(
                                Visibility::class . '::' . ($s3Params['visibility'] ?? 'PRIVATE')
                            )
                        ],
                    ],
                ],
            ]
        );

        return $app;
    }
}
