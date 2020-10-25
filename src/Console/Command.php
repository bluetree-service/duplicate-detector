<?php

namespace DuplicateDetector\Console;

use Symfony\Component\Console\Command\Command as BaseCommand;

class Command extends BaseCommand
{
    /**
     * @var Alias
     */
    protected $alias;

    /**
     * @var string
     */
    protected $commandName;

    /**
     * @var array
     */
    protected $config;

    /**
     * @param string $name
     * @param $alias Alias
     */
    public function __construct(string $name, Alias $alias)
    {
        $this->alias = $alias;
        $this->config = $this->readConfig('config');

        parent::__construct($name);
    }

    /**
     * @return null|string
     */
    protected function checkAlias() : ?string
    {
        if (\is_null($this->alias)) {
            return null;
        }

        return $this->alias->getAlias($this->commandName);
    }

    /**
     * @return null|string
     */
    protected function getAlias() : ?string
    {
        $alias = '';

        $checkAlias = $this->checkAlias();
        if ($checkAlias) {
            $alias = '(<comment>' . $checkAlias . '</comment>) ';
        }

        return $alias;
    }

    /**
     * @param string $configJsonName
     * @return array
     */
    protected function readConfig(string $configJsonName): array
    {
        try {
            $baseConfig = file_get_contents(__DIR__ . "/../../etc/$configJsonName.json");
            return \json_decode($baseConfig, true);
            //@todo add read from main /etc dir
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException($exception);
        }
    }
}
