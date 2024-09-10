<?php

declare(strict_types=1);
namespace In2code\Powermail\Controller;

use Exception;
use http\Exception\RuntimeException;
use In2code\Powermail\DataProcessor\DataProcessorRunner;
use In2code\Powermail\Domain\Factory\MailFactory;
use In2code\Powermail\Domain\Model\Form;
use In2code\Powermail\Domain\Model\Mail;
use In2code\Powermail\Domain\Service\ConfigurationService;
use In2code\Powermail\Domain\Service\Mail\SendDisclaimedMailPreflight;
use In2code\Powermail\Domain\Service\Mail\SendOptinConfirmationMailPreflight;
use In2code\Powermail\Domain\Service\Mail\SendReceiverMailPreflight;
use In2code\Powermail\Domain\Service\Mail\SendSenderMailPreflight;
use In2code\Powermail\Events\CheckIfMailIsAllowedToSaveEvent;
use In2code\Powermail\Events\FormControllerConfirmationActionEvent;
use In2code\Powermail\Events\FormControllerCreateActionAfterMailDbSavedEvent;
use In2code\Powermail\Events\FormControllerCreateActionAfterSubmitViewEvent;
use In2code\Powermail\Events\FormControllerCreateActionBeforeRenderViewEvent;
use In2code\Powermail\Events\FormControllerDisclaimerActionBeforeRenderViewEvent;
use In2code\Powermail\Events\FormControllerFormActionEvent;
use In2code\Powermail\Events\FormControllerInitializeObjectEvent;
use In2code\Powermail\Events\FormControllerOptinConfirmActionAfterPersistEvent;
use In2code\Powermail\Events\FormControllerOptinConfirmActionBeforeRenderViewEvent;
use In2code\Powermail\Exception\DeprecatedException;
use In2code\Powermail\Finisher\FinisherRunner;
use In2code\Powermail\Utility\ConfigurationUtility;
use In2code\Powermail\Utility\DatabaseUtility;
use In2code\Powermail\Utility\HashUtility;
use In2code\Powermail\Utility\LocalizationUtility;
use In2code\Powermail\Utility\ObjectUtility;
use In2code\Powermail\Utility\SessionUtility;
use In2code\Powermail\Utility\TemplateUtility;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use function in_array;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Annotation as ExtbaseAnnotation;
use TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Class FormController
 */
class FormController extends AbstractController
{
    /**
     * @var PersistenceManager
     */
    protected PersistenceManager $persistenceManager;

    /**
     * @var DataProcessorRunner
     */
    protected DataProcessorRunner $dataProcessorRunner;

    /**
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function formAction(): ResponseInterface
    {
        if (!ArrayUtility::isValidPath($this->settings, 'main/form')) {
            return $this->htmlResponse();
        }
        /** @var Form $form */
        $form = $this->formRepository->findByUid($this->settings['main']['form']);

        /** @var FormControllerFormActionEvent $event */
        $event = $this->eventDispatcher->dispatch(
            GeneralUtility::makeInstance(FormControllerFormActionEvent::class, $form, $this)
        );
        $form = $event->getForm();
        SessionUtility::saveFormStartInSession($this->settings, $form);
        $this->view->assignMultiple(
            [
                'form' => $form,
                'ttContentData' => $this->contentObject->data,
                'messageClass' => $this->messageClass,
                'action' => ($this->settings['main']['confirmation'] ? 'checkConfirmation' : 'checkCreate'),
            ]
        );

        return $this->htmlResponse();
    }

    /**
     * Workaround for TYPO3 v12
     * It turned out that it does not cover all use cases
     * Will be removed for TYPO3 v13 without any replacement. Functionality is already in `initializeConfirmationAction`
     *
     * @param Mail $mail
     * @return ResponseInterface
     * @throws DeprecatedException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     * @throws NoSuchArgumentException
     * @deprecated since version 12.3.2, will be removed in version 13.0.0
     */
    public function checkConfirmationAction(Mail $mail): ResponseInterface
    {
        trigger_error(
            'EXT:powermail -- Method "checkConfirmationAction" is deprecated since version 12.3.2, will be removed in version 13.0.0',
            E_USER_DEPRECATED
        );

        $response = $this->forwardIfFormParamsDoNotMatch();

        if ($response !== null) {
            return $response;
        }

        $response = $this->forwardIfMailParamEmpty();

        if ($response !== null) {
            return $response;
        }

        return (new ForwardResponse('confirmation'))->withArguments($this->request->getArguments());
    }

    /**
     * @return void
     * @throws DeprecatedException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     * @throws NoSuchArgumentException
     */
    public function initializeConfirmationAction(): void
    {
        // ToDo -- v13: move exceptions to methods
        $response = $this->forwardIfMailParamEmpty();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }

        $response = $this->forwardIfFormParamsDoNotMatch();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }

        $this->reformatParamsForAction();
        $this->debugVariables();
    }

    /**
     * Show a "Are your values ok?" message before final submit (if turned on)
     *
     * @param Mail $mail
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\UploadValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\InputValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\PasswordValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\CaptchaValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\SpamShieldValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\UniqueValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\ForeignValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\CustomValidator", param="mail")
     * @return ResponseInterface
     * @throws InvalidConfigurationTypeException
     * @noinspection PhpUnused
     */
    public function confirmationAction(Mail $mail): ResponseInterface
    {
        if ($mail->getUid() !== null) {
            return (new ForwardResponse('form'))->withoutArguments();
        }

        $event = GeneralUtility::makeInstance(FormControllerConfirmationActionEvent::class, $mail, $this);
        $this->eventDispatcher->dispatch($event);
        $mail = $event->getMail();

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->dataProcessorRunner->callDataProcessors(
            $mail,
            $this->actionMethodName,
            $this->settings,
            $this->contentObject
        );
        $this->prepareOutput($mail);

        return $this->htmlResponse();
    }

    /**
     *   Workaround for TYPO3 v12
     *   It turned out that it does not cover all use cases
     *   Will be removed for TYPO3 v13 without any replacement. Functionality is already in `initializeCreateAction`
     *
     * @return void
     * @throws DeprecatedException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     * @throws NoSuchArgumentException
     */
    public function initializeCheckCreateAction(): void
    {
        // ToDo -- v13: move exceptions to methods
        $response = $this->forwardIfMailParamEmpty();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }

        $response = $this->forwardIfFormParamsDoNotMatch();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }
    }

    /**
     *  Workaround for TYPO3 v12
     *  It turned out that it does not cover all use cases
     *  Will be removed for TYPO3 v13 without any replacement. Functionality is already in `initializeConformationAction`
     *
     * @param Mail $mail
     * @return ResponseInterface
     * @throws DeprecatedException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     * @throws NoSuchArgumentException
     * @deprecated since version 12.3.2, will be removed in version 13.0.0
     */
    public function checkCreateAction(Mail $mail): ResponseInterface
    {
        trigger_error(
            'EXT:powermail -- Method "checkCreateAction" is deprecated since version 12.3.2, will be removed in version 13.0.0',
            E_USER_DEPRECATED
        );
        $response = $this->forwardIfFormParamsDoNotMatch();

        if ($response !== null) {
            return $response;
        }

        $response = $this->forwardIfMailParamEmpty();

        if ($response !== null) {
            return $response;
        }

        return (new ForwardResponse('create'))->withArguments($this->request->getArguments());
    }

    /**
     * @return void
     * @throws DeprecatedException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws InvalidQueryException
     * @throws NoSuchArgumentException
     */
    public function initializeCreateAction(): void
    {
        // ToDo -- v13: move exceptions to methods
        $response = $this->forwardIfMailParamEmpty();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }

        $response = $this->forwardIfFormParamsDoNotMatch();
        if ($response !== null) {
            throw new PropagateResponseException($response);
        }

        $this->reformatParamsForAction();
        $this->debugVariables();
    }

    /**
     * @param Mail $mail
     * @param string $hash
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\UploadValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\InputValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\PasswordValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\CaptchaValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\SpamShieldValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\UniqueValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\ForeignValidator", param="mail")
     * @ExtbaseAnnotation\Validate("In2code\Powermail\Domain\Validator\CustomValidator", param="mail")
     * @return ResponseInterface
     * @throws IllegalObjectTypeException
     * @throws InvalidConfigurationTypeException
     * @throws UnknownObjectException
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function createAction(Mail $mail, string $hash = ''): ResponseInterface
    {
        /** @var SiteLanguage|null $language */
        $language = $this->request->getAttributes()['language'] ?? null;
        $langUid = 0;
        if ($language) {
            $langUid = $language->getLanguageId();
        }

        $event = GeneralUtility::makeInstance(FormControllerCreateActionBeforeRenderViewEvent::class, $mail, $hash, $this);
        $this->eventDispatcher->dispatch($event);
        $mail = $event->getMail();
        $hash = $event->getHash();

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->dataProcessorRunner->callDataProcessors(
            $mail,
            $this->actionMethodName,
            $this->settings,
            $this->contentObject
        );
        if ($this->isMailPersistActive($hash)) {
            $event = GeneralUtility::makeInstance(CheckIfMailIsAllowedToSaveEvent::class, $mail);
            $this->eventDispatcher->dispatch($event);
            $isSavingOfMailAllowed = $event->isSavingOfMailAllowed();
            if ($isSavingOfMailAllowed) {
                $this->saveMail($mail);
            }

            $event = GeneralUtility::makeInstance(FormControllerCreateActionAfterMailDbSavedEvent::class, $mail, $this, $hash);
            $this->eventDispatcher->dispatch($event);
            $mail = $event->getMail();
            $hash = $event->getHash();
        }

        if ($this->isNoOptin($mail, $hash)) {
            // mail to sender + receiver
            $this->sendMailPreflight($mail, $hash);
        } else {
            // optin mail
            try {
                $mailPreflight = GeneralUtility::makeInstance(
                    SendOptinConfirmationMailPreflight::class,
                    $this->settings,
                    $this->conf,
                    $this->request
                );
                $mailPreflight->sendOptinConfirmationMail($mail);
                $this->view->assign('optinActive', true);
            } catch (\Throwable $e) {
                if ($langUid === 0) {
                    $message = sprintf('Es konnte keine E-Mail an <%s> geschickt werden.
                        Prüfen Sie bitte, ob Sie die korrekte E-Mail Adresse eingegeben haben und schicken das Formular erneut ab!',
                        $mail->getSenderMail());
                } else {
                    $message = sprintf('An E-Mail to <%s> could not be sent.
                        Please check if you entered the correct E-Mail address and submit the form again!',
                        $mail->getSenderMail());
                }

                $this->addFlashMessage(
                    $message,
                    '',
                    \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
                );
                $this->messageClass = 'error';
            }
        }
        if ($this->isMailPersistActive($hash) && $isSavingOfMailAllowed) {
            $this->mailRepository->update($mail);
            $this->persistenceManager->persistAll();
        }

        $event = GeneralUtility::makeInstance(FormControllerCreateActionAfterSubmitViewEvent::class, $mail, $hash, $this);
        $this->eventDispatcher->dispatch($event);
        $mail = $event->getMail();
        $hash = $event->getHash();

        $this->prepareOutput($mail);

        $finisherRunner = GeneralUtility::makeInstance(FinisherRunner::class);
        /** @noinspection PhpUnhandledExceptionInspection */
        $finisherRunner->callFinishers(
            $mail,
            $this->isNoOptin($mail, $hash),
            $this->actionMethodName,
            $this->settings,
            $this->contentObject
        );

        return $this->htmlResponse();
    }

    /**
     * @param Mail $mail
     * @param string $hash
     * @return void
     *
     * !!! Anpassungen 23.09.2024 Sybille Peters - wir schicken jetzt 3 emails:
     *   1. Testmail an Sender (außer bei optin)
     *   2. E-Mail an Empfänger
     *   3. Email an Sender - Bestätigung
     */
    protected function sendMailPreflight(Mail $mail, string $hash = ''): void
    {
        /** @var SiteLanguage|null $language */
        $language = $this->request->getAttributes()['language'] ?? null;
        $langUid = 0;
        if ($language) {
            $langUid = $language->getLanguageId();
        }
        $hasOptin = (bool) ($this->settings['main']['optin']);


        // (test) mail to sender (is not necessary, if double optin is being used)
        /*
        if (!$hasOptin) {
            try {
                $subject = $this->settings['sender']['subject'] ?? '';
                if ($this->isSenderMailEnabled()
                    && $this->mailRepository->getSenderMailFromArguments($mail, '')
                    && $subject
                ) {
                    $mailPreflight = GeneralUtility::makeInstance(
                        SendSenderMailPreflight::class,
                        $this->settings,
                        $this->conf,
                        $this->request
                    );
                    if (!$mailPreflight->sendSenderTestMail($mail)) {
                        throw new RuntimeException('Sending test mail to sender failed!');
                    }
                }
            } catch (Throwable $exception) {
                $logger = ObjectUtility::getLogger(__CLASS__);
                $logger->critical('Test Mail to sender could not be sent', [$exception->getMessage()]);

                if ($langUid === 0) {
                    $message = sprintf('Es konnte keine Test E-Mail an Formularausfüller <%s> geschickt werden.
                            Prüfen Sie bitte, ob Sie die korrekte E-Mail Adresse eingegeben haben und schicken das Formular erneut ab!',
                        $mail->getSenderMail());
                } else {
                    $message = sprintf('A test E-Mail to the email given in the form <%s> could not be sent.
                            Please check if you entered the correct E-Mail address and submit the form again!',
                        $mail->getSenderMail());
                }
                $this->addFlashMessage(
                    $message,
                    '',
                    \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
                );
                $this->messageClass = 'error';
                return;
            }
        }
        */

        // mail to receiver
        $mailToReceiverError = false;
        try {
            $receiverSubject = $this->settings['receiver']['subject'] ?? '';
            if ($this->isReceiverMailEnabled() && $receiverSubject) {
                $mailPreflight = GeneralUtility::makeInstance(
                    SendReceiverMailPreflight::class,
                    $this->settings,
                    $this->request
                );
                $isSent = $mailPreflight->sendReceiverMail($mail, $hash);
                if ($isSent === false) {
                    throw new \RuntimeException('Email to receiver could not be sent!');
                }
            }
        } catch (Throwable $exception) {
            $mailToReceiverError = true;
            $logger = ObjectUtility::getLogger(__CLASS__);
            $logger->critical('Mail could not be sent', [$exception->getMessage()]);

            if ($langUid === 0) {
                $message = 'Fataler Fehler: Die E-Mail an den Empfänger konnte nicht geschickt werden!'
                    . ' In der Vergangenheit trat dies öfters auf, wenn im Formular eine fehlerhafte E-Mail eingegeben wurde.'
                    . ' In Ausnahmefällen deutet dies auf eine fehlerhafte Konfiguration hin.'
                    . ' Bitte füllen Sie das Formular korrekt aus oder nehmen Sie mit der auf der Formularseite genannten Personen Kontakt auf.';
            } else {
                $message = 'Fatal error: Email to receiver could not be sent!'
                    . ' Usually, this means that you entered an incorrect email in the form and the mail cannot be sent using the incorrect "From" email adddress.'
                    . ' In rare cases this means that the form was configured incorrectly.'
                    . ' Please fill out the form correctly or contact the persons listed as contact on this webpage.';
            }
            $this->addFlashMessage(
                $message,
                '',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            $this->messageClass = 'error';

            $mailToReceiverError = true;
        }

        if ($mailToReceiverError) {
            try {
                // send error notification email
                $mailPreflight = GeneralUtility::makeInstance(
                    SendSenderMailPreflight::class,
                    $this->settings,
                    $this->conf,
                    $this->request
                );
                $mailPreflight->sendToSenderReceiverMailFailed($mail, '');
            } catch (\Throwable $e) {
                // do nothing, we already logged error and added flash message
            }
            return;
        }

        // (confirmation) mail to sender
        try {
            $subject = $this->settings['sender']['subject'] ?? '';
            if ($this->isSenderMailEnabled()
                && $this->mailRepository->getSenderMailFromArguments($mail, '')
                && $subject
            ) {
                $mailPreflight = GeneralUtility::makeInstance(
                    SendSenderMailPreflight::class,
                    $this->settings,
                    $this->conf,
                    $this->request
                );
                if (!$mailPreflight->sendSenderMail($mail)) {
                    throw new RuntimeException('Sending confirmation mail to sender failed!');
                }
            }
        } catch (Throwable $exception) {
            $logger = ObjectUtility::getLogger(__CLASS__);
            $logger->critical('Confirmation Mail to sender could not be sent', [$exception->getMessage()]);

            if ($langUid === 0) {
                $message = sprintf('Es konnte keine Bestätigungs-E-Mail an den Formularausfüller <%s> geschickt werden!',
                    $mail->getSenderMail());
            } else {
                $message = sprintf('A confirmation E-Mail could not be sent to the email given in the form <%s>!',
                    $mail->getSenderMail());
            }
            $this->addFlashMessage(
                $message,
                '',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            $this->messageClass = 'error';
        }


    }

    /**
     * @param Mail $mail
     * @return void
     * @throws InvalidConfigurationTypeException
     * @throws Exception
     */
    protected function prepareOutput(Mail $mail): void
    {
        $this->view->assignMultiple(
            [
                'variablesWithMarkers' => $this->mailRepository->getVariablesWithMarkersFromMail($mail, true),
                'mail' => $mail,
                'marketingInfos' => SessionUtility::getMarketingInfos(),
                'messageClass' => $this->messageClass,
                'ttContentData' => $this->contentObject->data,
                'uploadService' => $this->uploadService,
                'powermail_rte' => $this->settings['thx']['body'],
                'powermail_all' => TemplateUtility::powermailAll($mail, 'web', $this->settings, $this->actionMethodName),
            ]
        );
        $this->view->assignMultiple($this->mailRepository->getVariablesWithMarkersFromMail($mail, true));
        $this->view->assignMultiple($this->mailRepository->getLabelsWithMarkersFromMail($mail));
    }

    /**
     * @param Mail $mail
     * @return void
     * @throws Exception
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws IllegalObjectTypeException
     * @codeCoverageIgnore
     */
    protected function saveMail(Mail $mail): void
    {
        $mailFactory = GeneralUtility::makeInstance(MailFactory::class);
        $mailFactory->prepareMailForPersistence($mail, $this->settings);
        $this->mailRepository->add($mail);
        $this->persistenceManager->persistAll();
    }

    /**
     * Confirm Double Optin
     *
     * @param int $mailUid
     * @param string $hash Given Hash String
     * @return ResponseInterface
     * @throws IllegalObjectTypeException
     * @throws UnknownObjectException
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function optinConfirmAction(int $mail, string $hash): ResponseInterface
    {
        $mail = $this->mailRepository->findByUid($mail);
        $labelKey = 'failed';

        /** @noinspection PhpUnhandledExceptionInspection */
        if ($mail !== null && HashUtility::isHashValid($hash, $mail)) {
            $event = GeneralUtility::makeInstance(
                FormControllerOptinConfirmActionBeforeRenderViewEvent::class,
                $mail,
                $hash,
                $this
            );

            $this->eventDispatcher->dispatch($event);
            $mail = $event->getMail();
            $hash = $event->getHash();

            $response = $this->forwardIfFormParamsDoNotMatchForOptinConfirm($mail);
            if ($response !== null) {
                return $response;
            }

            if ($mail->getHidden() && $this->isPersistActive()) {
                $mail->setHidden(false);
                $this->mailRepository->update($mail);
                $this->persistenceManager->persistAll();
                $event = GeneralUtility::makeInstance(
                    FormControllerOptinConfirmActionAfterPersistEvent::class,
                    $mail,
                    $hash,
                    $this
                );

                $this->eventDispatcher->dispatch($event);

                $mail = $event->getMail();
                $hash = $event->getHash();

                return (new ForwardResponse('create'))->withArguments(['mail' => $mail, 'hash' => $hash]);
            }
            if ($mail->getHidden() && !$this->isPersistActive()) {
                $this->prepareOutput($mail);
                DatabaseUtility::deleteMailAndAnswersFromDatabase($mail->getUid());
                return (new ForwardResponse('create'))->withArguments(['mail' => $mail, 'hash' => $hash]);
            }
            $labelKey = 'done';
        }
        $this->view->assign('labelKey', $labelKey);

        return $this->htmlResponse();
    }

    /**
     * @param int $mail
     * @param string $hash
     * @return ResponseInterface
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function disclaimerAction(int $mail, string $hash): ResponseInterface
    {
        $mail = $this->mailRepository->findByUid($mail);
        $status = false;

        if ($mail !== null && HashUtility::isHashValid($hash, $mail, 'disclaimer')) {
            $event = GeneralUtility::makeInstance(
                FormControllerDisclaimerActionBeforeRenderViewEvent::class,
                $mail,
                $hash,
                $this
            );
            $this->eventDispatcher->dispatch($event);

            $mail = $event->getMail();

            $mailService = GeneralUtility::makeInstance(
                SendDisclaimedMailPreflight::class,
                $this->settings,
                $this->conf,
                $this->request
            );
            $mailService->sendMail($mail);
            $this->mailRepository->removeFromDatabase($mail->getUid());
            $status = true;
        }
        $this->view->assign('status', $status);

        return $this->htmlResponse();
    }

    /**
     * @param string $referer Referer
     * @param int $language Frontend Language Uid
     * @param int $pid Page Id
     * @param bool $mobileDevice Is mobile device?
     * @return ResponseInterface
     * @noinspection PhpUnused
     * @codeCoverageIgnore
     */
    public function marketingAction(
        string $referer = '',
        int $language = 0,
        int $pid = 0,
        bool $mobileDevice = false
    ): ResponseInterface {
        SessionUtility::storeMarketingInformation($referer, $language, $pid, $mobileDevice, $this->settings);
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode([]));
        return $response;
    }

    /**
     * @return void
     * @codeCoverageIgnore
     * @throws Exception
     */
    public function initializeObject(): void
    {
        // @extensionScannerIgnoreLine Seems to be a false positive: getContentObject() is still correct in 9.0
        $this->contentObject = $this->configurationManager->getContentObject();
        $configurationService = GeneralUtility::makeInstance(ConfigurationService::class);
        $this->conf = $configurationService->getTypoScriptConfiguration();
        $this->settings = ConfigurationUtility::mergeTypoScript2FlexForm($this->settings);
        /** @var FormControllerInitializeObjectEvent $event */
        $event = $this->eventDispatcher->dispatch(
            GeneralUtility::makeInstance(FormControllerInitializeObjectEvent::class, $this->settings, $this)
        );
        $this->settings = $event->getSettings();
        if (ArrayUtility::isValidPath($this->settings, 'debug/settings') && $this->settings['debug']['settings']) {
            $logger = ObjectUtility::getLogger(__CLASS__);
            $logger->info('Powermail settings', $this->settings);
        }
    }

    /**
     * Forward to formAction if wrong form in plugin variables given
     *        used for createAction() and confirmationAction()
     */
    protected function forwardIfFormParamsDoNotMatch(): ?ForwardResponse
    {
        $arguments = $this->request->getArguments();
        if (isset($arguments['mail'])) {
            $formUid = null;
            if ($arguments['mail'] instanceof Mail) {
                $form = $arguments['mail']->getForm();
                if ($form !== null) {
                    $formUid = $form->getUid();
                }
            } else {
                $formUid = $arguments['mail']['form'] ?? null;
            }

            $formsToContent = GeneralUtility::intExplode(',', ($this->settings['main']['form'] ?? ''));
            if (!($formUid === null || in_array($formUid, $formsToContent, false))) {
                return new ForwardResponse('form');
            }
        }
        return null;
    }

    /**
     * Forward to formAction if no mail param given
     *
     * @return ForwardResponse|null
     */
    protected function forwardIfMailParamEmpty(): ?ForwardResponse
    {
        $arguments = $this->request->getArguments();
        if (empty($arguments['mail'])) {
            $logger = ObjectUtility::getLogger(__CLASS__);
            $logger->warning('Redirect (mail empty)', $arguments);

            return new ForwardResponse('form');
        }
        return null;
    }

    /**
     * Forward to formAction if wrong form in plugin variables given
     *        used in optinConfirmAction()
     *
     * @param Mail|null $mail
     * @return ForwardResponse|null
     */
    protected function forwardIfFormParamsDoNotMatchForOptinConfirm(Mail $mail = null): ?ForwardResponse
    {
        if ($mail !== null) {
            $formsToContent = GeneralUtility::intExplode(',', $this->settings['main']['form']);
            if (!in_array($mail->getForm()->getUid(), $formsToContent)) {
                $logger = ObjectUtility::getLogger(__CLASS__);
                $logger->warning('Redirect (optin)', [$formsToContent, (array)$mail]);

                return new ForwardResponse('form');
            }
        }
        return null;
    }

    /**
     * Always forward to formAction if a validation fails. Otherwise, it could happen that when
     * a validator for createAction fails, confirmationAction is called (if function is turned on) and same validators
     * are firing again
     *
     * @return ResponseInterface|null
     */
    protected function forwardToReferringRequest(): ?ResponseInterface
    {
        $response = new ForwardResponse('form');
        return $response->withArgumentsValidationResult($this->arguments->validate());
    }

    /**
     * Decide if the mail object should be persisted or not
     *        persist if
     *            - enabled with TypoScript AND hash is not set OR
     *            - optin is enabled AND hash is not set (even if disabled in TS)
     *
     * @param string $hash
     * @return bool
     */
    protected function isMailPersistActive(string $hash = ''): bool
    {
        return ($this->isPersistActive() || !empty($this->settings['main']['optin'])) && $hash === '';
    }

    /**
     * Check if mail should be send
     *        send when
     *            - optin is deaktivated OR
     *            - optin is active AND hash is correct
     *
     * @param Mail $mail
     * @param string $hash
     * @return bool
     * @throws Exception
     */
    protected function isNoOptin(Mail $mail, string $hash = ''): bool
    {
        return empty($this->settings['main']['optin']) ||
            (!empty($this->settings['main']['optin']) && HashUtility::isHashValid($hash, $mail));
    }

    /**
     * @return void
     * @codeCoverageIgnore
     */
    protected function debugVariables(): void
    {
        if (!empty($this->settings['debug']['variables'])) {
            $logger = ObjectUtility::getLogger(__CLASS__);
            $logger->info('Variables', GeneralUtility::_POST());
        }
    }

    /**
     * @return bool
     */
    protected function isPersistActive(): bool
    {
        return $this->settings['db']['enable'] === '1';
    }

    /**
     * @return bool
     */
    protected function isSenderMailEnabled(): bool
    {
        return $this->settings['sender']['enable'] === '1';
    }

    /**
     * @return bool
     */
    protected function isReceiverMailEnabled(): bool
    {
        return $this->settings['receiver']['enable'] === '1';
    }

    /**
     * @param DataProcessorRunner $dataProcessorRunner
     * @return void
     */
    public function injectDataProcessorRunner(DataProcessorRunner $dataProcessorRunner): void
    {
        $this->dataProcessorRunner = $dataProcessorRunner;
    }

    /**
     * @param PersistenceManager $persistenceManager
     * @return void
     */
    public function injectPersistenceManager(PersistenceManager $persistenceManager): void
    {
        $this->persistenceManager = $persistenceManager;
    }
}
