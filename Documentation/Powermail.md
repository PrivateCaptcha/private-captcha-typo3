# Powermail Integration

## Compatibility

Powermail support is optional and available only with this combination:

| Component | Supported version |
|---|---|
| TYPO3 | `^13.4` |
| Powermail | `>=13.2,<14.0` |

TYPO3 14 is not supported because no compatible Powermail release is available for this extension's current integration. The rest of Private Captcha remains available on TYPO3 14 without Powermail.

## Installation

From a TYPO3 13 project root, install a compatible Powermail release:

```bash
composer require in2code/powermail:^13.2
```

Install `private-captcha/typo3` as described in the main [README](../README.md), then apply TYPO3's database schema update. The Private Captcha adapter registers automatically when a compatible, active Powermail package is detected.

## Configuration

1. Open **Site > Private Captcha** as a TYPO3 administrator.
2. Select the site that renders the Powermail form.
3. Enter the API key and sitekey.
4. Enable **Powermail** and choose **Save**.
5. Edit the Powermail form and add the distinct **Private Captcha** field type.

The field type is offered only when Powermail protection is effective for the edited site. It does not replace or disable Powermail's built-in `captcha` field type or other spam protection.

## Submission Behavior

The adapter supports Powermail forms with or without a confirmation page.

### Direct submission

The submitted solution is verified once before Powermail creates the mail. Failure prevents creation. The raw solution is scrubbed from Powermail request and field data before normal persistence, spam checks, finishers, and output.

### Confirmation page

The solution is verified before confirmation is accepted. The adapter then replaces it with a short-lived opaque proof bound to the frontend session, form, Private Captcha field, content element, expected sitekey, and submitted business values.

Final creation consumes that proof atomically instead of calling Private Captcha a second time. The final step fails if the proof is missing, expired, reused, belongs to another session or form, or if protected business values or the expected sitekey changed. Returning from confirmation revokes the outstanding proof and requires a new widget solution.

Authenticated Powermail opt-in completion does not repeat CAPTCHA verification after the original accepted submission.

## Output and Persistence

The Private Captcha field is excluded from:

- confirmation output;
- submitted-value output;
- sender and receiver email output;
- opt-in output;
- normal Powermail persistence.

Only the bounded proof required to continue a multi-step submission is retained temporarily. Raw CAPTCHA solutions must not be added to custom templates, data processors, logs, or mail variables.

## Disabled and Invalid States

If Powermail protection is explicitly disabled in otherwise valid site configuration, an existing Private Captcha field is suppressed and the form proceeds without CAPTCHA protection. This is an intentionally unprotected state and should be visible in operational reviews. Malformed persisted site settings can still reject submission before the disabled flag is evaluated.

If protection is requested but credentials or runtime configuration are unavailable, the adapter fails closed and rejects submission rather than silently bypassing CAPTCHA.

After disabling or removing Powermail, review forms that contain the Private Captcha field and remove obsolete field records if they are no longer needed.

## Troubleshooting

If the field type is missing, verify the TYPO3 and Powermail versions, confirm that Powermail is active, and successfully save and test the site settings. For submission, schema, CSP, or logging problems, see [Backend Recovery and Troubleshooting](Recovery.md).
