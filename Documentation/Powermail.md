# Powermail

Supported only on TYPO3 `^13.4` with Powermail `>=13.2,<14.0`. Other integrations remain available on TYPO3 14.

## Install

```bash
composer require in2code/powermail:^13.2
```

Install this extension and run TYPO3's database schema update. The adapter registers automatically when compatible Powermail is active.

## Configure

1. Configure the form's site under **Site > Private Captcha**.
2. Enable **Powermail** and save successfully.
3. Add the distinct **Private Captcha** field to the Powermail form.

The field appears only when protection is effective for the edited site. Powermail's built-in `captcha` and other spam protection remain unchanged.

## Submission

| Flow | Behavior |
|---|---|
| Direct | Verifies once before mail creation, then removes the solution before persistence, spam checks, finishers, and output. |
| Confirmation | Verifies before confirmation and replaces the solution with a short-lived, single-use proof. Final creation consumes the proof without a second provider call. |

Confirmation proofs are bound to the session, form, CAPTCHA field, content element, sitekey, and submitted business values. Missing, expired, reused, or mismatched proofs fail. Returning from confirmation revokes the proof and requires a new solution.

Authenticated opt-in completion does not repeat verification.

The CAPTCHA field is omitted from confirmation, submitted values, email, opt-in output, and normal Powermail persistence. Do not expose raw solutions through custom templates, processors, logs, or mail variables.

## States

Explicitly disabling Powermail protection suppresses the field and allows unprotected submission. Invalid configuration may still fail before the disabled flag is evaluated. Enabled protection with missing credentials or runtime errors fails closed.

After removing Powermail, remove obsolete Private Captcha fields from affected forms.

If the field type is missing, check TYPO3/Powermail versions, package activation, and effective site configuration. See [Recovery and troubleshooting](Recovery.md) for schema, CSP, and logging checks.
