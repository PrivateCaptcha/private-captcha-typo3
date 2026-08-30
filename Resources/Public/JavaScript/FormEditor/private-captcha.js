export function bootstrap(formEditorApp) {
  formEditorApp.getPublisherSubscriber().subscribe(
    'view/stage/abstract/render/template/perform',
    (topic, args) => {
      const [formElement, template] = args;
      if (formElement.get('type') !== 'PrivateCaptcha') {
        return;
      }

      formEditorApp.getViewModel().getStage().renderSimpleTemplateWithValidators(formElement, template);
    },
  );
}
