<?php

namespace Core\Entity;

class OptionsBag
{
    /** @var AppParameters */
    protected $appParameters;

    /** @var array */
    protected $parsedOptions;

    /**
     * @var array (associative)
     * This options list will keep a copy of the parsed options even if an option get's removed by remove.
     */
    protected $optionsCollection;

    protected $overlays;

    private const OVERLAY_OPTIONS_PREFIX = "ol";

    /**
     * OptionsBag constructor.
     *
     * @param AppParameters $appParameters
     * @param string        $options
     */
    public function __construct(AppParameters $appParameters, string $options)
    {
        $this->appParameters = $appParameters;
        $this->parsedOptions = $this->parseOptions($options);
        $this->optionsCollection = $this->parsedOptions;
    }

    /**
     * Parse options: match options keys and merge default options with given ones
     *
     * @param string $options
     *
     * @return array
     */
    private function parseOptions(string $options): array
    {
        $defaultOptions = $this->appParameters->parameterByKey('default_options');
        $optionsKeys = $this->appParameters->parameterByKey('options_keys');
        $optionsSeparator = !empty($this->appParameters->parameterByKey('options_separator')) ?
            $this->appParameters->parameterByKey('options_separator') : ',';
        $optionsUrl = explode($optionsSeparator, $options);
        $options = [];

        $overlayOptions = array_filter($optionsUrl, function ($v) {
            return str_starts_with($v,  self::OVERLAY_OPTIONS_PREFIX);
        });
        $optionsUrl = array_diff_key($optionsUrl, $overlayOptions);

        foreach ($optionsUrl as $option) {
            $optArray = explode('_', $option);
            if (key_exists($optArray[0], $optionsKeys) && !empty($optionsKeys[$optArray[0]])) {
                $options[$optionsKeys[$optArray[0]]] = $optArray[1];
            }
        }

        // Move the first overlay-image to the beginning of the array so we can associate 
        // the options until the next image to the first image
        foreach (array_values($overlayOptions) as $key => $value) {
            if (str_starts_with($value, 'oli_')) {
                array_unshift($overlayOptions, $value);
                unset($overlayOptions[$key + 1]);
                break;
            }
        }

        $this->overlays = array();

        foreach ($overlayOptions as $key => $value) {
            $optArray = explode('_', $value);
            if (str_starts_with($optArray[0], 'oli')) {
                $this->overlays[] = array();
            }
            $this->overlays[count($this->overlays) - 1][$optionsKeys[$optArray[0]]] = $optArray[1];
        }

        return array_merge($defaultOptions, $options);
    }

    /**
     * Return a hashed string based on image output options
     *
     * @param string $imageUrl
     *
     * @return string
     */
    public function hashedOptionsAsString(string $imageUrl): string
    {
        // Remove rf from the generated image
        $keys = $this->asArray();
        if (!empty($keys['refresh']) && $keys['refresh'] == 1) {
            $keys['refresh'] = null;
        }

        return md5(implode('.', $keys) . $imageUrl);
    }

    /**
     * Returns a parameter by name.
     *
     * @param string $key     The key
     * @param mixed  $default The default value if the parameter key does not exist
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->parsedOptions) ? $this->parsedOptions[$key] : $default;
    }

    /**
     * Returns true if the parameter is defined.
     *
     * @param string $key The key
     *
     * @return bool true if the parameter exists, false otherwise
     */
    public function has($key)
    {
        return array_key_exists($key, $this->parsedOptions);
    }

    /**
     * Removes a parameter.
     *
     * @param string $key The key
     */
    public function remove($key)
    {
        unset($this->parsedOptions[$key]);
    }

    /**
     * @return array
     */
    public function asArray(): array
    {
        return $this->parsedOptions;
    }

    /**
     * Returns a parameter by name.
     * These options will not be removed by the extract method.
     *
     * @param string $key The key
     *
     * @return mixed
     */
    public function getOption($key)
    {
        return array_key_exists($key, $this->optionsCollection) ? $this->optionsCollection[$key] : '';
    }

    /**
     * Returns the AppParamters Object
     * @return AppParameters
     */
    public function appParameters()
    {
        return $this->appParameters;
    }

    /**
     * Update a parameter by name.
     * These options will not update the main options list.
     *
     * @param string $key
     * @param string $value
     *
     * @return OptionsBag
     */
    public function setOption(string $key, string $value)
    {
        $this->optionsCollection[$key] = $value;
        $this->parsedOptions[$key] = $value;

        return $this;
    }

    public function getOverlays(): array
    {
        return $this->overlays;
    }

    public function hasOverlays(): bool
    {
        return count($this->overlays) > 0;
    }
}
