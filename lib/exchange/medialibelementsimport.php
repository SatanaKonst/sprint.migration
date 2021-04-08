<?php

namespace Sprint\Migration\Exchange;

use Sprint\Migration\AbstractExchange;
use Sprint\Migration\Exceptions\ExchangeException;
use Sprint\Migration\Exceptions\RestartException;
use Sprint\Migration\Locale;
use XMLReader;

class MedialibElementsImport extends AbstractExchange
{
    protected $converter;

    /**
     * @param callable $converter
     *
     * @throws ExchangeException
     * @throws RestartException
     */
    public function execute(callable $converter)
    {
        $this->converter = $converter;
        $params = $this->exchangeEntity->getRestartParams();

        if (!isset($params['total'])) {
            $this->exchangeEntity->exitIf(
                !is_file($this->file),
                Locale::getMessage('ERR_EXCHANGE_FILE_NOT_FOUND')
            );

            $reader = new XMLReader();
            $reader->open($this->getExchangeFile());
            $params['total'] = 0;
            $params['offset'] = 0;
            $exchangeVersion = 0;
            while ($reader->read()) {
                if ($this->isOpenTag($reader, 'items')) {
                    $exchangeVersion = (int)$reader->getAttribute('exchangeVersion');
                }
                if ($this->isOpenTag($reader, 'item')) {
                    $params['total']++;
                }
            }
            $reader->close();

            if (!$exchangeVersion || $exchangeVersion < self::EXCHANGE_VERSION) {
                $this->exchangeEntity->exitWithMessage(
                    Locale::getMessage('ERR_EXCHANGE_VERSION')
                );
            }
        }

        $reader = new XMLReader();
        $reader->open($this->getExchangeFile());
        $index = 0;

        while ($reader->read()) {
            if ($this->isOpenTag($reader, 'item')) {
                $collect = ($index >= $params['offset'] && $index < $params['offset'] + $this->getLimit());
                $restart = ($index >= $params['offset'] + $this->getLimit());
                $finish = ($index >= $params['total'] - 1);

                if ($collect) {
                    $this->collectItem($reader);
                }

                if ($finish || $restart) {
                    $this->outProgress('', ($index + 1), $params['total']);
                }

                if ($restart) {
                    $params['offset'] = $index;
                    $this->exchangeEntity->setRestartParams($params);
                    $this->restart();
                }
                $index++;
            }
        }

        $reader->close();
        unset($params['offset']);
        unset($params['total']);
        $this->exchangeEntity->setRestartParams($params);
    }

    /**
     * @param XMLReader $reader
     *
     */
    protected function collectItem(XMLReader $reader)
    {
        $fields = [];
        if ($this->isOpenTag($reader, 'item')) {
            do {
                $reader->read();
                $field = $this->collectField($reader, 'field');
                if ($field) {
                    $fields[] = $field;
                }
            } while (!$this->isCloseTag($reader, 'item'));

            $convertedItem = $this->convertItem($fields);
            if ($convertedItem) {
                call_user_func($this->converter, $convertedItem);
            }
        }
    }

    /**
     * @param $fields
     *
     * @return array|bool
     */
    protected function convertItem($fields)
    {
        if (empty($fields)) {
            return false;
        }

        $convertedFields = [];
        foreach ($fields as $field) {
            if ($field['name'] == 'PATH') {
                $convertedFields[$field['name']] = $this->convertFieldFile($field);
            } elseif ($field['name'] == 'COLLECTION_ID') {
                $convertedFields[$field['name']] = $this->convertFieldCollection($field);
            } else {
                $convertedFields[$field['name']] = $this->convertFieldString($field);
            }
        }

        if (empty($convertedFields)) {
            return false;
        }

        $collectionId = $convertedFields['COLLECTION_ID'];
        unset($convertedFields['COLLECTION_ID']);

        return [
            'collection_id' => $collectionId,
            'fields'        => $convertedFields,
        ];
    }

    protected function convertFieldFile($field)
    {
        return $this->makeFileValue($field['value'][0]);
    }

    protected function convertFieldString($field)
    {
        return $field['value'][0]['value'];
    }

    protected function convertFieldCollection($field)
    {
        return $field['value'][0]['value'];
    }
}
