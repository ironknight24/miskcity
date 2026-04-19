<?php

namespace Drupal\career_application\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * Form for submitting career job applications.
 *
 * Handles collection of applicant personal information, resume upload,
 * and storage of the application in the database.
 */
class CareerApplyForm extends FormBase
{
    // Constant for Drupal render array theme key
    private const THEME_KEY = '#theme';
    // Constant for Drupal render array type key
    private const TYPE_KEY = '#type';
    // Constant for Drupal render array title key
    private const TITLE_KEY = '#title';
    // Constant for Drupal render array attributes key
    private const ATTRIBUTES_KEY = '#attributes';
    // Constant for Drupal render array required key
    private const REQUIRED_KEY = '#required';
    // CSS classes for form input styling (Tailwind CSS classes)
    private const INPUT_CLASSES = [
        'form-input',
        'w-full',
        'rounded-md',
        'border',
        'border-gray-300',
        'focus:border-yellow-500',
        'focus:ring-yellow-500',
        'text-gray-700',
        'text-base',
        'p-2.5',
    ];

    /**
     * Returns the unique form ID.
     *
     * @return string
     *   The form ID used to identify this form
     */
    public function getFormId()
    {
        return 'career_apply_form';
    }

    /**
     * Builds the career application form.
     *
     * Constructs form fields for applicant information including personal details,
     * contact information, and resume upload with file validation constraints.
     *
     * @param array $form
     *   The form array being built
     * @param FormStateInterface $form_state
     *   The current state of the form
     * @param int $nid
     *   The node ID of the career job posting (passed as route parameter)
     *
     * @return array
     *   Complete form render array with all fields and validation
     */
    public function buildForm(array $form, FormStateInterface $form_state, $nid = NULL)
    {
        // Set the form theme
        $form[self::THEME_KEY] = 'career_apply_form';

        // Hidden field storing the job posting node ID
        $form['nid'] = [
            self::TYPE_KEY => 'hidden',
            '#value' => $nid,
        ];

        // First name text input field
        $form['first_name'] = $this->buildInputField('textfield', 'First Name');

        // Last name text input field
        $form['last_name'] = $this->buildInputField('textfield', 'Last Name');

        // Email input field
        $form['email'] = $this->buildInputField('email', 'Email');

        // Mobile number input field
        $form['mobile'] = $this->buildInputField('tel', 'Mobile Number');

        // Gender selection dropdown
        $form['gender'] = [
            self::TYPE_KEY => 'select',
            self::TITLE_KEY => $this->t('Gender'),
            self::ATTRIBUTES_KEY => [
                'class' => [
                    'form-select',
                    'rounded-md',
                    'border',
                    'border-gray-300',
                    'focus:border-yellow-500',
                    'focus:ring-yellow-500',
                    'text-gray-700',
                    'text-base',
                    'p-2.5'
                ],
            ],
            '#options' => [
                'male' => $this->t('Male'),
                'female' => $this->t('Female'),
                'other' => $this->t('Other'),
            ],
            self::REQUIRED_KEY => TRUE,
        ];

        // Resume/CV file upload field with file type constraints
        $form['resume'] = [
            self::TYPE_KEY => 'managed_file',
            self::TITLE_KEY => $this->t('Upload your CV*'),
            self::REQUIRED_KEY => TRUE,
            // Directory where uploaded files will be stored
            '#upload_location' => 'public://resumes/',
            // File validation constraints (size and MIME type)
            '#constraints' => [
                new \Symfony\Component\Validator\Constraints\File([
                    'maxSize' => '5M',
                    'mimeTypes' => [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ],
                    'mimeTypesMessage' => $this->t('Please upload a valid PDF or Word document.'),
                ]),
            ],
        ];

        // Submit button
        $form['submit'] = [
            self::TYPE_KEY => 'submit',
            '#value' => $this->t('Apply Now'),
        ];

        // Attach custom CSS library for form styling
        $form['#attached']['library'][] = 'career_application/career-apply-form-library';
        return $form;
    }

    /**
     * Handles form submission and saves application data.
     *
     * Processes the submitted form data, marks the uploaded resume file as permanent,
     * and stores all application information in the career_applications database table.
     * After successful submission, redirects to the success page.
     *
     * @param array $form
     *   The form array
     * @param FormStateInterface $form_state
     *   The current state of the form containing submitted values
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Retrieve all form submitted values
        $values = $form_state->getValues();
        // Get the ID of the currently logged-in user
        $uid = \Drupal::currentUser()->id();
        // Extract the file ID from the resume upload field
        $resume_fid = $values['resume'][0] ?? NULL;

        // Mark the uploaded resume file as permanent in the file system
        if ($resume_fid) {
            // Load the file entity by its ID
            $file = \Drupal\file\Entity\File::load($resume_fid);
            if ($file) {
                // Set the file status to permanent so it won't be deleted
                $file->setPermanent();
                // Save the file entity
                $file->save();
            }
        }

        // Insert application record into the career_applications database table
        \Drupal::database()->insert('career_applications')->fields([
            'uid' => $uid,
            'nid' => $values['nid'],
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'email' => $values['email'],
            'mobile' => $values['mobile'],
            'gender' => $values['gender'],
            'resume_fid' => $resume_fid,
            // Store current timestamp as application submission time
            'applied' => \Drupal::time()->getCurrentTime(),
        ])->execute();

        // Redirect to the success page after successful application submission
        $form_state->setRedirect('career_application.success_page');
    }

    /**
     * Helper method to build standard form input fields.
     *
     * Creates a reusable text input field with consistent styling and attributes.
     *
     * @param string $type
     *   The input field type (textfield, email, tel, etc.)
     * @param string $title
     *   The label/title for the input field
     *
     * @return array
     *   Form field render array with styling and required attributes
     */
    private function buildInputField(string $type, string $title): array
    {
        return [
            self::TYPE_KEY => $type,
            self::TITLE_KEY => $this->t($title),
            self::ATTRIBUTES_KEY => [
                // Placeholder text displayed in the field
                'placeholder' => $this->t($title),
                // CSS classes for Tailwind styling
                'class' => self::INPUT_CLASSES,
            ],
            self::REQUIRED_KEY => TRUE,
        ];
    }
}
