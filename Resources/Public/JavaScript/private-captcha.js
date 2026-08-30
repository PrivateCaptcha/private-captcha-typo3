const widgetSelector = '[data-private-captcha-widget="true"]';
const submissionControlSelector = 'button, input[type="submit"], input[type="image"]';

const widgetsIn = (container) => Array.from(container.querySelectorAll(widgetSelector));
const submissionControls = (form) => Array.from(form.ownerDocument.querySelectorAll(submissionControlSelector))
  .filter((control) => control.form === form && (control.type === 'submit' || control.type === 'image'));

const disableSubmission = (form) => {
  submissionControls(form).forEach((button) => {
    if (!button.disabled) {
      button.disabled = true;
      button.dataset.privateCaptchaDisabled = 'true';
    }
  });
};

const enableSubmission = (form) => {
  submissionControls(form)
    .filter((button) => button.dataset.privateCaptchaDisabled === 'true')
    .forEach((button) => {
      button.disabled = false;
      delete button.dataset.privateCaptchaDisabled;
    });
};

const clearSolution = (widget) => {
  const form = widget.closest('form');
  const fieldName = widget.dataset.solutionField;
  if (!form || !fieldName) {
    return;
  }
  Array.from(form.elements)
    .filter((field) => field.name === fieldName)
    .forEach((field) => {
      field.value = '';
    });
};

const markIncomplete = (widget) => {
  clearSolution(widget);
  delete widget.dataset.privateCaptchaComplete;
  const form = widget.closest('form');
  if (form) {
    disableSubmission(form);
  }
};

const markComplete = (widget, event) => {
  let solution = '';
  try {
    if (event.detail?.element === widget && typeof event.detail.widget?.solution === 'function') {
      solution = event.detail.widget.solution();
    }
  } catch {
    markIncomplete(widget);
    return;
  }
  if (typeof solution !== 'string' || solution === '') {
    markIncomplete(widget);
    return;
  }

  widget.dataset.privateCaptchaComplete = 'true';
  const form = widget.closest('form');
  if (form && widgetsIn(form).every((candidate) => candidate.dataset.privateCaptchaComplete === 'true')) {
    enableSubmission(form);
  }
};

const resetWidget = (widget) => {
  markIncomplete(widget);
  const storeVariable = widget.dataset.storeVariable;
  const captcha = storeVariable ? widget[storeVariable] : null;
  if (captcha && typeof captcha.reset === 'function') {
    captcha.reset();
  }
};

const setupWidget = (widget) => {
  if (widget.dataset.privateCaptchaLifecycle === 'true') {
    return;
  }
  widget.dataset.privateCaptchaLifecycle = 'true';
  markIncomplete(widget);
  widget.addEventListener('privatecaptcha:init', () => markIncomplete(widget));
  widget.addEventListener('privatecaptcha:reset', () => markIncomplete(widget));
  widget.addEventListener('privatecaptcha:error', () => markIncomplete(widget));
  widget.addEventListener('privatecaptcha:finish', (event) => markComplete(widget, event));

  const form = widget.closest('form');
  if (form && form.dataset.privateCaptchaLifecycle !== 'true') {
    form.dataset.privateCaptchaLifecycle = 'true';
    form.addEventListener('reset', () => widgetsIn(form).forEach(resetWidget));
  }
};

const setup = () => widgetsIn(document).forEach(setupWidget);

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setup, { once: true });
} else {
  setup();
}

window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    widgetsIn(document).forEach(resetWidget);
  }
});
