<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Functional\Powermail\Fixtures;

use In2code\Powermail\DataProcessor\AbstractDataProcessor;
use In2code\Powermail\Domain\Model\Form;

final class NormalizeAnswersDataProcessor extends AbstractDataProcessor
{
    public function normalizeAnswersDataProcessor(): void
    {
        if ($this->getActionMethodName() !== 'confirmationAction') {
            return;
        }
        $mutation = null;
        foreach ($this->mail->getAnswers() as $answer) {
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
            $replacement->setUid(300);
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
