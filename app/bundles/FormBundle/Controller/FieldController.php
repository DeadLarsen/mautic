<?php

namespace Mautic\FormBundle\Controller;

use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\FormBundle\Model\FieldModel;
use Mautic\FormBundle\Model\FormModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

class FieldController extends CommonFormController
{
    /**
     * @var FormModel|AbstractCommonModel
     */
    private $formModel;

    /**
     * @var FieldModel|AbstractCommonModel
     */
    private $formFieldModel;

    /**
     * @var FormFieldHelper
     */
    private $fieldHelper;

    public function initialize(ControllerEvent $event)
    {
        $this->formModel      = $this->getModel('form');
        $this->formFieldModel = $this->getModel('form.field');
        $this->fieldHelper    = $this->get('mautic.helper.form.field_helper');
    }

    /**
     * Generates new form and processes post data.
     *
     * @return JsonResponse
     */
    public function newAction()
    {
        $success = 0;
        $valid   = $cancelled   = false;
        $method  = $this->request->getMethod();
        $session = $this->get('session');

        if ('POST' == $method) {
            $formField = $this->request->request->get('formfield');
            $fieldType = $formField['type'];
            $formId    = $formField['formId'];
        } else {
            $fieldType = $this->request->query->get('type');
            $formId    = $this->request->query->get('formId');
            $formField = [
                'type'     => $fieldType,
                'formId'   => $formId,
                'parent'   => $this->request->query->get('parent'),
            ];
        }

        $customComponents = $this->formModel->getCustomComponents();
        $customParams     = (isset($customComponents['fields'][$fieldType])) ? $customComponents['fields'][$fieldType] : false;
        //ajax only for form fields
        if (!$fieldType ||
            !$this->request->isXmlHttpRequest() ||
            !$this->get('mautic.security')->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
        ) {
            return $this->modalAccessDenied();
        }

        // Generate the form
        $form = $this->getFieldForm($formId, $formField);

        if (!empty($customParams)) {
            $formField['isCustom']         = true;
            $formField['customParameters'] = $customParams;
        }

        //Check for a submitted form and process it
        if ('POST' == $method) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $success = 1;

                    //form is valid so process the data
                    $keyId = 'new'.hash('sha1', uniqid(mt_rand()));

                    //save the properties to session
                    $fields          = $session->get('mautic.form.'.$formId.'.fields.modified', []);
                    $formData        = $form->getData();
                    $formField       = array_merge($formField, $formData);
                    $formField['id'] = $keyId;

                    // Get aliases in order to generate a new one for the new field
                    $aliases = [];
                    foreach ($fields as $f) {
                        $aliases[] = $f['alias'];
                    }

                    // Generate or ensure a unique alias
                    $alias              = empty($formField['alias']) ? $formField['label'] : $formField['alias'];
                    $formField['alias'] = $this->getModel('form.field')->generateAlias($alias, $aliases);

                    // Force required for captcha if not a honeypot
                    if ('captcha' == $formField['type']) {
                        $formField['isRequired'] = !empty($formField['properties']['captcha']);
                    }

                    // Add it to the next to last assuming the last is the submit button
                    if (count($fields)) {
                        $lastField = end($fields);
                        $lastKey   = key($fields);
                        array_pop($fields);

                        $fields[$keyId]   = $formField;
                        $fields[$lastKey] = $lastField;
                    } else {
                        $fields[$keyId] = $formField;
                    }

                    $session->set('mautic.form.'.$formId.'.fields.modified', $fields);

                    // Keep track of used lead fields
                    $usedLeadFields = $this->get('session')->get('mautic.form.'.$formId.'.fields.leadfields', []);
                    if (!empty($formData['leadField']) && empty($formData['parent'])) {
                        $usedLeadFields[$keyId] = $formData['leadField'];
                    } else {
                        unset($usedLeadFields[$keyId]);
                    }
                    $session->set('mautic.form.'.$formId.'.fields.leadfields', $usedLeadFields);
                } else {
                    $success = 0;
                }
            }
        }

        $viewParams = ['type' => $fieldType];
        if ($cancelled || $valid) {
            $closeModal = true;
        } else {
            $closeModal                = false;
            $viewParams['tmpl']        = 'field';
            $viewParams['form']        = (isset($customParams['formTheme'])) ? $this->setFormTheme($form, 'MauticFormBundle:Builder:field.html.php', $customParams['formTheme']) : $form->createView();
            $viewParams['fieldHeader'] = (!empty($customParams)) ? $this->get('translator')->trans($customParams['label']) : $this->get('translator')->transConditional('mautic.core.type.'.$fieldType, 'mautic.form.field.type.'.$fieldType);
        }

        $passthroughVars = [
            'mauticContent' => 'formField',
            'success'       => $success,
            'route'         => false,
        ];

        if (!empty($keyId)) {
            $entity     = new Field();
            $blank      = $entity->convertToArray();
            $formField  = array_merge($blank, $formField);
            $formEntity = $this->formModel->getEntity($formId);

            $passthroughVars['parent']    = $formField['parent'];
            $passthroughVars['fieldId']   = $keyId;
            $template                     = (!empty($customParams)) ? $customParams['template'] : 'MauticFormBundle:Field:'.$fieldType.'.html.php';
            $passthroughVars['fieldHtml'] = $this->renderView(
                'MauticFormBundle:Builder:fieldwrapper.html.php',
                [
                    'isConditional'        => !empty($formField['parent']),
                    'template'             => $template,
                    'inForm'               => true,
                    'field'                => $formField,
                    'id'                   => $keyId,
                    'formId'               => $formId,
                    'formName'             => null === $formEntity ? 'newform' : $formEntity->generateFormName(),
                    'contactFields'        => $this->getModel('lead.field')->getFieldListWithProperties(),
                    'companyFields'        => $this->getModel('lead.field')->getFieldListWithProperties('company'),
                    'inBuilder'            => true,
                    'fields'               => $this->fieldHelper->getChoiceList($customComponents['fields']),
                    'viewOnlyFields'       => $customComponents['viewOnlyFields'],
                    'formFields'           => $fields,
                ]
            );
        }

        if ($closeModal) {
            //just close the modal
            $passthroughVars['closeModal'] = 1;

            return new JsonResponse($passthroughVars);
        }

        return $this->ajaxAction([
            'contentTemplate' => 'MauticFormBundle:Builder:'.$viewParams['tmpl'].'.html.php',
            'viewParameters'  => $viewParams,
            'passthroughVars' => $passthroughVars,
        ]);
    }

    /**
     * Generates edit form and processes post data.
     *
     * @param int $objectId
     *
     * @return JsonResponse
     */
    public function editAction($objectId)
    {
        $session   = $this->get('session');
        $method    = $this->request->getMethod();
        $formfield = $this->request->request->get('formfield', []);
        $formId    = 'POST' === $method ? ($formfield['formId'] ?? '') : $this->request->query->get('formId');
        $fields    = $session->get('mautic.form.'.$formId.'.fields.modified', []);
        $success   = 0;
        $valid     = $cancelled = false;
        $formField = array_key_exists($objectId, $fields) ? $fields[$objectId] : [];

        if ($formField) {
            $fieldType = $formField['type'];

            //ajax only for form fields
            if (!$fieldType ||
                !$this->request->isXmlHttpRequest() ||
                !$this->get('mautic.security')->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
            ) {
                return $this->modalAccessDenied();
            }

            // Generate the form
            $form = $this->getFieldForm($formId, $formField);

            //Check for a submitted form and process it
            if ('POST' == $method) {
                if (!$cancelled = $this->isFormCancelled($form)) {
                    if ($valid = $this->isFormValid($form)) {
                        $success = 1;

                        //form is valid so process the data

                        //save the properties to session
                        $session  = $this->get('session');
                        $fields   = $session->get('mautic.form.'.$formId.'.fields.modified');
                        $formData = $form->getData();

                        //overwrite with updated data
                        $formField = array_merge($fields[$objectId], $formData);

                        if (false !== strpos($objectId, 'new')) {
                            // Get aliases in order to generate update for this one
                            $aliases = [];
                            foreach ($fields as $k => $f) {
                                if ($k != $objectId) {
                                    $aliases[] = $f['alias'];
                                }
                            }
                            $formField['alias'] = $this->formFieldModel->generateAlias(
                                $formField['alias'] ?? $formField['label'],
                                $aliases
                            );
                        }

                        // Force required for captcha if not a honeypot
                        if ('captcha' == $formField['type']) {
                            $formField['isRequired'] = !empty($formField['properties']['captcha']);
                        }

                        $fields[$objectId] = $formField;
                        $session->set('mautic.form.'.$formId.'.fields.modified', $fields);

                        // Keep track of used lead fields
                        $usedLeadFields = $this->get('session')->get('mautic.form.'.$formId.'.fields.leadfields', []);
                        if (!empty($formData['leadField']) && empty($formData['parent'])) {
                            $usedLeadFields[$objectId] = $formData['leadField'];
                        } else {
                            unset($usedLeadFields[$objectId]);
                        }
                        $session->set('mautic.form.'.$formId.'.fields.leadfields', $usedLeadFields);
                    }
                }
            }

            $viewParams       = ['type' => $fieldType];
            $customComponents = $this->formModel->getCustomComponents();
            $customParams     = (isset($customComponents['fields'][$fieldType])) ? $customComponents['fields'][$fieldType] : false;

            if ($cancelled || $valid) {
                $closeModal = true;
            } else {
                $closeModal         = false;
                $viewParams['tmpl'] = 'field';
                $viewParams['form'] = (isset($customParams['formTheme'])) ? $this->setFormTheme(
                    $form,
                    'MauticFormBundle:Builder:field.html.php',
                    $customParams['formTheme']
                ) : $form->createView();
                $viewParams['fieldHeader'] = (!empty($customParams))
                    ? $this->get('translator')->trans($customParams['label'])
                    : $this->get(
                        'translator'
                    )->transConditional('mautic.core.type.'.$fieldType, 'mautic.form.field.type.'.$fieldType);
            }

            $passthroughVars = [
                'mauticContent' => 'formField',
                'success'       => $success,
                'route'         => false,
            ];

            $passthroughVars['fieldId'] = $objectId;
            $template                   = (!empty($customParams)) ? $customParams['template'] : 'MauticFormBundle:Field:'.$fieldType.'.html.php';

            //prevent undefined errors
            $entity    = new Field();
            $blank     = $entity->convertToArray();
            $formField = array_merge($blank, $formField);

            $passthroughVars['fieldHtml'] = $this->renderView(
                'MauticFormBundle:Builder:fieldwrapper.html.php',
                [
                    'isConditional'        => !empty($formField['parent']),
                    'template'             => $template,
                    'inForm'               => true,
                    'field'                => $formField,
                    'id'                   => $objectId,
                    'formId'               => $formId,
                    'contactFields'        => $this->getModel('lead.field')->getFieldListWithProperties(),
                    'companyFields'        => $this->getModel('lead.field')->getFieldListWithProperties('company'),
                    'inBuilder'            => true,
                    'fields'               => $this->fieldHelper->getChoiceList($customComponents['fields']),
                    'formFields'           => $fields,
                    'viewOnlyFields'       => $customComponents['viewOnlyFields'],
                ]
            );

            if ($closeModal) {
                //just close the modal
                $passthroughVars['closeModal'] = 1;

                return new JsonResponse($passthroughVars);
            }

            return $this->ajaxAction(
                [
                    'contentTemplate' => 'MauticFormBundle:Builder:'.$viewParams['tmpl'].'.html.php',
                    'viewParameters'  => $viewParams,
                    'passthroughVars' => $passthroughVars,
                ]
            );
        }

        return new JsonResponse(['success' => 0]);
    }

    /**
     * Deletes the entity.
     *
     * @param int $objectId
     *
     * @return JsonResponse
     */
    public function deleteAction($objectId)
    {
        $session = $this->get('session');
        $formId  = $this->request->query->get('formId');
        $fields  = $session->get('mautic.form.'.$formId.'.fields.modified', []);
        $delete  = $session->get('mautic.form.'.$formId.'.fields.deleted', []);

        //ajax only for form fields
        if (!$this->request->isXmlHttpRequest() ||
            !$this->get('mautic.security')->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
        ) {
            return $this->accessDenied();
        }

        $formField = (array_key_exists($objectId, $fields)) ? $fields[$objectId] : null;

        if ('POST' === $this->request->getMethod() && null !== $formField) {
            $usedLeadFields = $session->get('mautic.form.'.$formId.'.fields.leadfields');

            // Allow to select the lead field from the delete field again
            $unusedLeadField = array_search($formField['leadField'], $usedLeadFields);
            if (!empty($formField['leadField']) && empty($formField['parent']) && false !== $unusedLeadField) {
                unset($usedLeadFields[$unusedLeadField]);
                $session->set('mautic.form.'.$formId.'.fields.leadfields', $usedLeadFields);
            }

            //add the field to the delete list
            if (!in_array($objectId, $delete)) {
                $delete[] = $objectId;
                $session->set('mautic.form.'.$formId.'.fields.deleted', $delete);
            }

            $dataArray = [
                'mauticContent' => 'formField',
                'success'       => 1,
                'route'         => false,
            ];
        } else {
            $dataArray = ['success' => 0];
        }

        return new JsonResponse($dataArray);
    }

    /**
     * @param $formId
     *
     * @return mixed
     */
    private function getFieldForm($formId, array $formField)
    {
        //fire the form builder event
        $customComponents = $this->getModel('form.form')->getCustomComponents();
        $customParams     = (isset($customComponents['fields'][$formField['type']])) ? $customComponents['fields'][$formField['type']] : false;

        $form = $this->getModel('form.field')->createForm(
            $formField,
            $this->get('form.factory'),
            (!empty($formField['id'])) ?
                $this->generateUrl('mautic_formfield_action', ['objectAction' => 'edit', 'objectId' => $formField['id']])
                : $this->generateUrl('mautic_formfield_action', ['objectAction' => 'new']),
            ['customParameters' => $customParams]
        );
        $form->get('formId')->setData($formId);

        $event      = new FormBuilderEvent($this->get('translator'));
        $this->dispatcher->dispatch($event, FormEvents::FORM_ON_BUILD);
        $event->addValidatorsToBuilder($form);

        return $form;
    }
}
