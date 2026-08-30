<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail\Fixtures;

use In2code\Powermail\DataProcessor\AbstractDataProcessor;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Form;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

final class NormalizeAnswersDataProcessor extends AbstractDataProcessor
{
    public function normalizeAnswersDataProcessor(): void
    {
        if ($this->getActionMethodName() !== 'confirmationAction') {
            return;
        }
        $mutation = null;
        foreach ($this->mail->getAnswers() as $answer) {
            if (!$answer instanceof Answer) {
                throw new \RuntimeException('Powermail answer collection is invalid.');
            }
            $value = $answer->getValue();
            if (is_string($value)) {
                if (in_array($value, ['mutate-binding', 'remove-captcha-field', 'replace-form'], true)) {
                    $mutation = $value;
                }
                $answer->setValue(strtoupper($value));
            }
        }
        if ($mutation === null) {
            return;
        }
        $form = $this->mail->getForm();
        if (!$form instanceof Form) {
            return;
        }
        if ($mutation === 'replace-form') {
            $replacement = new Form();
            $replacement->_setProperty('uid', 300);
            $replacement->setPages(new ObjectStorage());
            $this->mail->setForm($replacement);
            return;
        }
        foreach ($form->getFields() as $field) {
            if ($field->getType() !== 'privateCaptcha') {
                continue;
            }
            if ($mutation === 'remove-captcha-field') {
                $field->getPage()?->removeField($field);
            } else {
                $field->setMarker('mutated-security');
            }
        }
    }
}
