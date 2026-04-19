<?php

namespace Drupal\login_logout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\login_logout\Service\PasswordRecoveryService;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

class UserForgotPasswordForm extends FormBase
{

    protected $passwordRecoveryService;
    protected $tempStoreFactory;

    public function __construct(PasswordRecoveryService $passwordRecoveryService, PrivateTempStoreFactory $temp_store_factory)
    {
        $this->passwordRecoveryService = $passwordRecoveryService;
        $this->tempStoreFactory = $temp_store_factory;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('login_logout.password_recovery_service'),
            $container->get('tempstore.private')
        );
    }

    public function getFormId()
    {
        return 'login_logout_forgot_password_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {

        $form['#theme'] = 'login_logout_forgot_password';

        $form['email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email'),
            '#required' => TRUE,
        ];

        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#attributes' => [
                'class' => [
                    'bg-yellow-500',
                    'text-white',
                    'rounded-xl',
                    'px-6',
                    'py-2',
                    'cursor-pointer',
                    'hover:bg-yellow-600',
                    'transition',
                    'button',
                ],
            ],
            '#value' => $this->t('Send Recovery Email'),
            '#button_type' => 'primary',
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $email = $form_state->getValue('email');

        try {
            $response = $this->passwordRecoveryService->recoverPassword($email);

            if (!empty($response)) {
                // Save email in temp store for current user
                $tempstore = $this->tempStoreFactory->get('login_logout');
                $tempstore->set('recovery_email', $email);

                $form_state->setRedirect('login_logout.password_recovery_status');
                return;
            } else {
                $this->messenger()->addError($this->t('Failed to send password recovery email. Please try again.'));
            }
        } catch (\Exception $e) {
            \Drupal::logger('login_logout')->error('Password recovery failed: @msg', ['@msg' => $e->getMessage()]);
            $this->messenger()->addError($this->t('Error: @msg', ['@msg' => $e->getMessage()]));
        }
    }
}
