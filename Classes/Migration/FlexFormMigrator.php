<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class FlexFormMigrator
{
    /** @return array{action:string, fields:list<string>, values:array<string, string>} */
    public function inspect(string $xml): array
    {
        $document = $this->parse($xml);
        $xpath = new DOMXPath($document);
        $fields = [];
        $values = [];
        $actions = [];
        foreach ($xpath->query('//field[@index]') ?: [] as $field) {
            if (!$field instanceof DOMElement) {
                continue;
            }
            $fieldName = $field->getAttribute('index');
            $fields[$fieldName] = true;
            foreach ($xpath->query('.//value[@index]', $field) ?: [] as $value) {
                if (!$value instanceof DOMElement || trim($value->textContent) === '') {
                    continue;
                }
                $normalized = trim(preg_replace('/\s+/', '', $value->textContent) ?? '');
                $values[$fieldName . '@' . $value->getAttribute('index')] = trim($value->textContent);
                if ($fieldName === 'switchableControllerActions') {
                    $actions[$normalized] = true;
                }
            }
        }
        if (count($actions) !== 1) {
            throw new RuntimeException('The legacy FlexForm must contain one unambiguous switchableControllerActions value.');
        }
        $fieldNames = array_keys($fields);
        sort($fieldNames, SORT_STRING);
        ksort($values, SORT_STRING);
        return ['action' => (string)array_key_first($actions), 'fields' => $fieldNames, 'values' => $values];
    }

    /** @param array<string, string> $renamedFields */
    public function transform(string $xml, array $renamedFields): string
    {
        $document = $this->parse($xml);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//field[@index="switchableControllerActions"]') ?: [] as $field) {
            $field->parentNode?->removeChild($field);
        }
        foreach ($renamedFields as $from => $to) {
            foreach ($xpath->query('//field[@index=' . $this->xpathLiteral($from) . ']') ?: [] as $field) {
                if ($field instanceof DOMElement) {
                    $field->setAttribute('index', $to);
                }
            }
        }
        $result = $document->saveXML();
        if ($result === false) {
            throw new RuntimeException('Cannot serialize the migrated FlexForm.');
        }
        return $result;
    }

    private function parse(string $xml): DOMDocument
    {
        if ($xml === '') {
            throw new RuntimeException('The legacy pi1 FlexForm is empty.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('DOCTYPE and ENTITY declarations are forbidden in pi_flexform.');
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('The legacy pi1 FlexForm is malformed XML.');
        }
        return $document;
    }

    private function xpathLiteral(string $value): string
    {
        return "'" . str_replace("'", "', \"'\", '", $value) . "'";
    }
}
