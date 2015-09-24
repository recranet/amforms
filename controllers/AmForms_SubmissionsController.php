<?php
namespace Craft;

/**
 * AmForms - Submissions controller
 */
class AmForms_SubmissionsController extends BaseController
{
    protected $allowAnonymous = array('actionSaveSubmission', 'actionSaveSubmissionByAngular');

    /**
     * Show submissions.
     */
    public function actionIndex()
    {
        $variables = array(
            'elementType' => AmFormsModel::ElementTypeSubmission
        );
        $this->renderTemplate('amForms/submissions/index', $variables);
    }

    /**
     * Edit a submission.
     *
     * @param array $variables
     */
    public function actionEditSubmission(array $variables = array())
    {
        // Do we have a submission model?
        if (! isset($variables['submission'])) {
            // We require a submission ID
            if (empty($variables['submissionId'])) {
                throw new HttpException(404);
            }

            // Get submission if available
            $submission = craft()->amForms_submissions->getSubmissionById($variables['submissionId']);
            if (! $submission) {
                throw new Exception(Craft::t('No submission exists with the ID “{id}”.', array('id' => $variables['submissionId'])));
            }
        }
        else {
            $submission = $variables['submission'];
        }

        // Get form if available
        $form = craft()->amForms_forms->getFormById($submission->formId);
        if (! $form) {
            throw new Exception(Craft::t('No form exists with the ID “{id}”.', array('id' => $submission->formId)));
        }

        // Get tabs
        $tabs = array();
        $layoutTabs = $submission->getFieldLayout()->getTabs();
        foreach ($layoutTabs as $tab) {
            $tabs[$tab->id] = array(
                'label' => $tab->name,
                'url' => '#tab' . $tab->sortOrder
            );
        }

        // Add notes to tabs
        $tabs['notes'] = array(
            'label' => Craft::t('Notes'),
            'url'   => $submission->getCpEditUrl() . '/notes'
        );

        // Set variables
        $variables['submission'] = $submission;
        $variables['form'] = $form;
        $variables['tabs'] = $tabs;
        $variables['layoutTabs'] = $layoutTabs;

        $this->renderTemplate('amforms/submissions/_edit', $variables);
    }

    /**
     * Save a form submission.
     */
    public function actionSaveSubmission()
    {
        $this->requirePostRequest();

        // Get the form
        $handle = craft()->request->getRequiredParam('handle');
        $form = craft()->amForms_forms->getFormByHandle($handle);
        if (! $form) {
            throw new Exception(Craft::t('No form exists with the handle “{handle}”.', array('handle' => $handle)));
        }

        // Get the submission from CP?
        if (craft()->request->isCpRequest()) {
            $submissionId = craft()->request->getPost('submissionId');
        }

        // Get the submission
        if (isset($submissionId)) {
            $submission = craft()->amForms_submissions->getSubmissionById($submissionId);

            if (! $submission) {
                throw new Exception(Craft::t('No submission exists with the ID “{id}”.', array('id' => $submissionId)));
            }
        }
        else {
            $submission = new AmForms_SubmissionModel();
        }

        // Add the form to the submission
        $submission->form = $form;
        $submission->formId = $form->id;

        // Set attributes
        $fieldsLocation = craft()->request->getParam('fieldsLocation', 'fields');
        $submission->ipAddress = craft()->request->getUserHostAddress();
        $submission->userAgent = craft()->request->getUserAgent();
        $submission->setContentFromPost($fieldsLocation);
        $submission->setContentPostLocation($fieldsLocation);

        // Front-end submission, trigger AntiSpam or reCAPTCHA?
        if (! craft()->request->isCpRequest()) {
            // Where was this submission submitted?
            $submission->submittedFrom = craft()->request->getUrlReferrer();

            // Validate AntiSpam settings
            $submission->spamFree = craft()->amForms_antispam->verify();

            // Redirect our spammers before reCAPTCHA can be triggered
            if (! $submission->spamFree) {
                $this->_doRedirect($submission);
            }

            // Validate reCAPTCHA
            if (craft()->amForms_settings->isSettingValueEnabled('googleRecaptchaEnabled', AmFormsModel::SettingRecaptcha)) {
                $submission->spamFree = craft()->amForms_recaptcha->verify();
            }
        }

        // Save submission
        if (craft()->amForms_submissions->saveSubmission($submission)) {
            // Notification
            if (! craft()->request->isCpRequest()) {
                craft()->amForms_submissions->emailSubmission($submission);
            }

            // Redirect
            if (craft()->request->isAjaxRequest()) {
                $this->returnJson(array('success' => true));
            }
            elseif (craft()->request->isCpRequest()) {
                craft()->userSession->setNotice(Craft::t('Submission saved.'));

                $this->redirectToPostedUrl($submission);
            }
            else {
                $this->_doRedirect($submission);
            }
        }
        else {
            if (craft()->request->isAjaxRequest()) {
                $return = array(
                    'success' => false,
                    'errors' => $submission->getErrors()
                );
                $this->returnJson($return);
            }
            elseif (craft()->request->isCpRequest()) {
                craft()->userSession->setError(Craft::t('Couldn’t save submission.'));

                // Send the submission back to the template
                craft()->urlManager->setRouteVariables(array(
                    'submission' => $submission
                ));
            }
            else {
                // Remember active submissions
                craft()->amForms_submissions->setActiveSubmission($submission);

                // Return the submission by the form's handle, for custom HTML possibilities
                craft()->urlManager->setRouteVariables(array(
                    $form->handle => $submission
                ));
            }
        }
    }

    /**
     * Save a form submission by Angular.
     */
    public function actionSaveSubmissionByAngular()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        // Get the form
        $handle = craft()->request->getRequiredParam('handle');
        $form = craft()->amForms_forms->getFormByHandle($handle);
        if (! $form) {
            throw new Exception(Craft::t('No form exists with the handle “{handle}”.', array('handle' => $handle)));
        }

        // Get the submission
        $submission = new AmForms_SubmissionModel();

        // Add the form to the submission
        $submission->form = $form;
        $submission->formId = $form->id;

        // Set attributes
        $fieldsLocation = craft()->request->getParam('fieldsLocation', 'fields');
        $fieldsContent = json_decode(craft()->request->getParam($fieldsLocation), true);
        $submission->ipAddress = craft()->request->getUserHostAddress();
        $submission->userAgent = craft()->request->getUserAgent();
        $submission->setContentFromPost($fieldsContent);
        $submission->setContentPostLocation($fieldsLocation);

        // Upload possible files
        foreach ($form->getFieldLayout()->getTabs() as $tab) {
            // Tab fields
            $fields = $tab->getFields();
            foreach ($fields as $layoutField) {
                // Get actual field
                $field = $layoutField->getField();

                // Look for possible file
                if ($field->type == 'Assets') {
                    if (! empty($_FILES[ $field->handle ]['name'])) {
                        // Get folder
                        $folderId = $field->getFieldType()->resolveSourcePath();

                        // Get the file
                        $file = $_FILES[ $field->handle ];
                        $fileName = AssetsHelper::cleanAssetName($file['name']);

                        // Save the file to a temp location and pass this on to the source type implementation
                        $filePath = AssetsHelper::getTempFilePath(IOHelper::getExtension($fileName));
                        move_uploaded_file($file['tmp_name'], $filePath);

                        $response = craft()->assets->insertFileByLocalPath($filePath, $fileName, $folderId);

                        // Make sure the file is removed.
                        IOHelper::deleteFile($filePath, true);

                        // Prevent sensitive information leak. Just in case.
                        $response->deleteDataItem('filePath');

                        // Add file to submission
                        $fileId = $response->getDataItem('fileId');
                        $submission->getContent()->setAttribute($field->handle, array($fileId));
                    }
                }
            }
        }

        // Save submission
        if (craft()->amForms_submissions->saveSubmission($submission)) {
            // Notification
            craft()->amForms_submissions->emailSubmission($submission);

            // Response
            $this->returnJson(array('success' => true));
        }
        else {
            $return = array(
                'success' => false,
                'errors' => $submission->getErrors()
            );
            $this->returnJson($return);
        }
    }

    /**
     * Delete a submission.
     *
     * @return void
     */
    public function actionDeleteSubmission()
    {
        $this->requirePostRequest();

        // Get the submission
        $submissionId = craft()->request->getRequiredParam('submissionId');
        $submission = craft()->amForms_submissions->getSubmissionById($submissionId);
        if (! $submission) {
            throw new Exception(Craft::t('No submission exists with the ID “{id}”.', array('id' => $submissionId)));
        }

        // Delete submission
        $success = craft()->amForms_submissions->deleteSubmission($submission);

        $this->redirectToPostedUrl($submission);
    }

    /**
     * Do redirect with {placeholders} support.
     *
     * @param AmForms_SubmissionModel $submission
     */
    private function _doRedirect(AmForms_SubmissionModel $submission)
    {
        $vars = array_merge(
            array(
                'siteUrl' => craft()->getSiteUrl()
            ),
            $submission->getContent()->getAttributes(),
            $submission->getAttributes()
        );

        $this->redirectToPostedUrl($vars);
    }
}
