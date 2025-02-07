<?php

namespace Mautic\LeadBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\CoreBundle\Helper\ArrayHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Controller\FrequencyRuleTrait;
use Mautic\LeadBundle\Controller\LeadDetailsTrait;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Deduplicate\Exception\SameContactException;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @property LeadModel $model
 */
class LeadApiController extends CommonApiController
{
    use CustomFieldsApiControllerTrait;
    use FrequencyRuleTrait;
    use LeadDetailsTrait;

    public const MODEL_ID = 'lead.lead';

    public function initialize(ControllerEvent $event)
    {
        $this->model            = $this->getModel(self::MODEL_ID);
        $this->entityClass      = Lead::class;
        $this->entityNameOne    = 'contact';
        $this->entityNameMulti  = 'contacts';
        $this->serializerGroups = ['leadDetails', 'frequencyRulesList', 'doNotContactList', 'userList', 'stageList', 'publishDetails', 'ipAddress', 'tagList', 'utmtagsList'];
        $this->setCleaningRules();
        parent::initialize($event);
    }

    /**
     * Obtains a list of users for lead owner edits.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getOwnersAction()
    {
        if (!$this->get('mautic.security')->isGranted(
            ['lead:leads:create', 'lead:leads:editown', 'lead:leads:editother'],
            'MATCH_ONE'
        )
        ) {
            return $this->accessDenied();
        }

        $filter  = $this->request->query->get('filter', null);
        $limit   = $this->request->query->get('limit', null);
        $start   = $this->request->query->get('start', null);
        $users   = $this->model->getLookupResults('user', $filter, $limit, $start);
        $view    = $this->view($users, Response::HTTP_OK);
        $context = $view->getContext()->setGroups(['userList']);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Obtains a list of custom fields.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getFieldsAction()
    {
        if (!$this->get('mautic.security')->isGranted(['lead:leads:editown', 'lead:leads:editother'], 'MATCH_ONE')) {
            return $this->accessDenied();
        }

        $fields = $this->getModel('lead.field')->getEntities(
            [
                'filter' => [
                    'force' => [
                        [
                            'column' => 'f.isPublished',
                            'expr'   => 'eq',
                            'value'  => true,
                            'object' => 'lead',
                        ],
                    ],
                ],
            ]
        );

        $view    = $this->view($fields, Response::HTTP_OK);
        $context = $view->getContext()->setGroups(['leadFieldList']);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Obtains a list of notes on a specific lead.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getNotesAction($id)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $entity->getPermissionUser())) {
            return $this->accessDenied();
        }

        $results = $this->getModel('lead.note')->getEntities(
            [
                'start'  => $this->request->query->get('start', 0),
                'limit'  => $this->request->query->get('limit', $this->coreParametersHelper->get('default_pagelimit')),
                'filter' => [
                    'string' => $this->request->query->get('search', ''),
                    'force'  => [
                        [
                            'column' => 'n.lead',
                            'expr'   => 'eq',
                            'value'  => $entity,
                        ],
                    ],
                ],
                'orderBy'    => $this->request->query->get('orderBy', 'n.dateAdded'),
                'orderByDir' => $this->request->query->get('orderByDir', 'DESC'),
            ]
        );

        [$notes, $count] = $this->prepareEntitiesForView($results);

        $view = $this->view(
            [
                'total' => $count,
                'notes' => $notes,
            ],
            Response::HTTP_OK
        );

        $context = $view->getContext()->setGroups(['leadNoteDetails']);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Obtains a list of devices on a specific lead.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getDevicesAction($id)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $entity->getPermissionUser())) {
            return $this->accessDenied();
        }

        $results = $this->getModel('lead.device')->getEntities(
            [
                'start'  => $this->request->query->get('start', 0),
                'limit'  => $this->request->query->get('limit', $this->coreParametersHelper->get('default_pagelimit')),
                'filter' => [
                    'string' => $this->request->query->get('search', ''),
                    'force'  => [
                        [
                            'column' => 'd.lead',
                            'expr'   => 'eq',
                            'value'  => $entity,
                        ],
                    ],
                ],
                'orderBy'    => $this->request->query->get('orderBy', 'd.dateAdded'),
                'orderByDir' => $this->request->query->get('orderByDir', 'DESC'),
            ]
        );

        [$devices, $count] = $this->prepareEntitiesForView($results);

        $view = $this->view(
            [
                'total'   => $count,
                'devices' => $devices,
            ],
            Response::HTTP_OK
        );

        $context = $view->getContext()->setGroups(['leadDeviceDetails']);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Obtains a list of contact segments the contact is in.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getListsAction($id)
    {
        $entity = $this->model->getEntity($id);
        if (null !== $entity) {
            if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $entity->getPermissionUser())) {
                return $this->accessDenied();
            }

            $lists = $this->model->getLists($entity, true, true);

            foreach ($lists as &$l) {
                unset($l['leads'][0]['leadlist_id']);
                unset($l['leads'][0]['lead_id']);

                $l = array_merge($l, $l['leads'][0]);

                unset($l['leads']);
            }

            $view = $this->view(
                [
                    'total' => count($lists),
                    'lists' => $lists,
                ],
                Response::HTTP_OK
            );

            return $this->handleView($view);
        }

        return $this->notFound();
    }

    /**
     * Obtains a list of contact companies the contact is in.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getCompaniesAction($id)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $entity->getPermissionUser())) {
            return $this->accessDenied();
        }

        $companies = $this->model->getCompanies($entity);

        $view = $this->view(
            [
                'total'     => count($companies),
                'companies' => $companies,
            ],
            Response::HTTP_OK
        );

        return $this->handleView($view);
    }

    /**
     * Obtains a list of campaigns the lead is part of.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getCampaignsAction($id)
    {
        $entity = $this->model->getEntity($id);
        if (null !== $entity) {
            if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $entity->getPermissionUser())) {
                return $this->accessDenied();
            }

            /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
            $campaignModel = $this->getModel('campaign');
            $campaigns     = $campaignModel->getLeadCampaigns($entity, true);

            foreach ($campaigns as &$c) {
                if (!empty($c['lists'])) {
                    $c['listMembership'] = array_keys($c['lists']);
                    unset($c['lists']);
                }

                unset($c['leads'][0]['campaign_id']);
                unset($c['leads'][0]['lead_id']);

                $c = array_merge($c, $c['leads'][0]);

                unset($c['leads']);
            }

            $view = $this->view(
                [
                    'total'     => count($campaigns),
                    'campaigns' => $campaigns,
                ],
                Response::HTTP_OK
            );

            return $this->handleView($view);
        }

        return $this->notFound();
    }

    /**
     * Obtains a list of contact events.
     *
     * @param $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getActivityAction($id)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($entity)) {
            return $this->accessDenied();
        }

        return $this->getAllActivityAction($entity);
    }

    /**
     * Obtains a list of contact events.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getAllActivityAction($lead = null)
    {
        $canViewOwn    = $this->security->isGranted('lead:leads:viewown');
        $canViewOthers = $this->security->isGranted('lead:leads:viewother');

        if (!$canViewOthers && !$canViewOwn) {
            return $this->accessDenied();
        }

        $filters = $this->sanitizeEventFilter(InputHelper::clean($this->request->get('filters', [])));
        $limit   = (int) $this->request->get('limit', 25);
        $page    = (int) $this->request->get('page', 1);
        $order   = InputHelper::clean($this->request->get('order', ['timestamp', 'DESC']));

        [$events, $serializerGroups] = $this->model->getEngagements($lead, $filters, $order, $page, $limit, false);

        $view    = $this->view($events);
        $context = $view->getContext()->setGroups($serializerGroups);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Adds a DNC to the contact.
     *
     * @param $id
     * @param $channel
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addDncAction($id, $channel)
    {
        $entity = $this->model->getEntity((int) $id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($entity, 'edit')) {
            return $this->accessDenied();
        }

        $channelId = (int) $this->request->request->get('channelId');
        if ($channelId) {
            $channel = [$channel => $channelId];
        }

        // If no reason is set, default to 3 (manual)
        $reason = (int) $this->request->request->get('reason', DoNotContact::MANUAL);

        // If a reason is set, but it's empty or 0, show an error.
        if (0 === $reason) {
            return $this->returnError(
                'Invalid reason code given',
                Response::HTTP_BAD_REQUEST,
                'Reason code needs to be an integer and higher than 0.'
            );
        }

        $comments = InputHelper::clean($this->request->request->get('comments'));

        /** @var \Mautic\LeadBundle\Model\DoNotContact $doNotContact */
        $doNotContact = $this->get('mautic.lead.model.dnc');
        $doNotContact->addDncForContact($entity->getId(), $channel, $reason, $comments);
        $view = $this->view([$this->entityNameOne => $entity]);

        return $this->handleView($view);
    }

    /**
     * Removes a DNC from the contact.
     *
     * @param $id
     * @param $channel
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function removeDncAction($id, $channel)
    {
        /** @var \Mautic\LeadBundle\Model\DoNotContact $doNotContact */
        $doNotContact = $this->get('mautic.lead.model.dnc');

        $entity = $this->model->getEntity((int) $id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($entity, 'edit')) {
            return $this->accessDenied();
        }

        $result = $doNotContact->removeDncForContact($entity->getId(), $channel);
        $view   = $this->view(
            [
                'recordFound'        => $result,
                $this->entityNameOne => $entity,
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Add/Remove a UTM Tagset to/from the contact.
     *
     * @param int              $id
     * @param string           $method
     * @param array<mixed>|int $data
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function applyUtmTagsAction($id, $method, $data)
    {
        $entity = $this->model->getEntity((int) $id);

        if (null === $entity) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($entity, 'edit')) {
            return $this->accessDenied();
        }

        // calls add/remove method as appropriate
        $result = $this->model->$method($entity, $data);

        if (false === $result) {
            return $this->badRequest();
        }

        if ('removeUtmTags' == $method) {
            $view = $this->view(
                [
                    'recordFound'        => $result,
                    $this->entityNameOne => $entity,
                ]
            );
        } else {
            $view = $this->view([$this->entityNameOne => $entity]);
        }

        return $this->handleView($view);
    }

    /**
     * Adds a UTM Tagset to the contact.
     *
     * @param int $id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addUtmTagsAction($id)
    {
        return $this->applyUtmTagsAction($id, 'addUTMTags', $this->request->request->all());
    }

    /**
     * Remove a UTM Tagset for the contact.
     *
     * @param int $id
     * @param int $utmid
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function removeUtmTagsAction($id, $utmid)
    {
        return $this->applyUtmTagsAction($id, 'removeUtmTags', (int) $utmid);
    }

    /**
     * Creates new entity from provided params.
     *
     * @return object
     */
    public function getNewEntity(array $params)
    {
        return $this->model->checkForDuplicateContact($params);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareParametersForBinding($parameters, $entity, $action)
    {
        // Unset the tags from params to avoid a validation error
        if (isset($parameters['tags'])) {
            unset($parameters['tags']);
        }

        if (count($entity->getTags()) > 0) {
            foreach ($entity->getTags() as $tag) {
                $parameters['tags'][] = $tag->getId();
            }
        }

        return $parameters;
    }

    /**
     * {@inheritdoc}
     *
     * @param Lead   $entity
     * @param array  $parameters
     * @param        $form
     * @param string $action
     */
    protected function preSaveEntity(&$entity, $form, $parameters, $action = 'edit')
    {
        if ('edit' === $action) {
            // Merge existing duplicate contact based on unique fields if exist
            // new endpoints will leverage getNewEntity in order to return the correct status codes
            $existingEntity = $this->model->checkForDuplicateContact($this->entityRequestParameters);
            $contactMerger  = $this->get('mautic.lead.merger');
            \assert($contactMerger instanceof ContactMerger);

            if ($entity->getId() && $existingEntity->getId()) {
                try {
                    $entity = $contactMerger->merge($entity, $existingEntity);
                } catch (SameContactException $exception) {
                }
            } elseif ($existingEntity->getId()) {
                $entity = $existingEntity;
            }
        }

        $manipulatorObject = $this->inBatchMode ? 'api-batch' : 'api-single';

        $entity->setManipulator(new LeadManipulator(
            'lead',
            $manipulatorObject,
            null,
            $this->get('mautic.helper.user')->getUser()->getName()
        ));

        if (isset($parameters['companies'])) {
            $this->model->modifyCompanies($entity, $parameters['companies']);
            unset($parameters['companies']);
        }

        if (isset($parameters['owner'])) {
            $owner = $this->getModel('user.user')->getEntity((int) $parameters['owner']);
            $entity->setOwner($owner);
            unset($parameters['owner']);
        }

        if (isset($parameters['stage'])) {
            $stage = $this->getModel('stage.stage')->getEntity((int) $parameters['stage']);
            $entity->setStage($stage);
            unset($parameters['stage']);
        }

        if (isset($this->entityRequestParameters['tags'])) {
            $this->model->modifyTags($entity, $this->entityRequestParameters['tags'], null, false);
        }

        //Since the request can be from 3rd party, check for an IP address if included
        if (isset($this->entityRequestParameters['ipAddress'])) {
            $ipAddress = $this->get('mautic.helper.ip_lookup')->getIpAddress($this->entityRequestParameters['ipAddress']);
            \assert($ipAddress instanceof IpAddress);

            if (!$entity->getIpAddresses()->contains($ipAddress)) {
                $entity->addIpAddress($ipAddress);
            }

            unset($this->entityRequestParameters['ipAddress']);
        }

        // Check for lastActive date
        if (isset($this->entityRequestParameters['lastActive'])) {
            $lastActive = new DateTimeHelper($this->entityRequestParameters['lastActive']);
            $entity->setLastActive($lastActive->getDateTime());
            unset($this->entityRequestParameters['lastActive']);
        }

        // Batch DNC settings
        if (!empty($parameters['doNotContact']) && is_array($parameters['doNotContact'])) {
            foreach ($parameters['doNotContact'] as $dnc) {
                $channel  = !empty($dnc['channel']) ? $dnc['channel'] : 'email';
                $comments = !empty($dnc['comments']) ? $dnc['comments'] : '';

                $reason = (int) ArrayHelper::getValue('reason', $dnc, DoNotContact::MANUAL);

                /** @var DoNotContactModel $doNotContact */
                $doNotContact = $this->get('mautic.lead.model.dnc');

                if (DoNotContact::IS_CONTACTABLE === $reason) {
                    if (!empty($entity->getId())) {
                        // Remove DNC record
                        $doNotContact->removeDncForContact($entity->getId(), $channel, false);
                    }
                } elseif (empty($entity->getId())) {
                    // Contact doesn't exist yet. Directly create a DNC record on the entity.
                    $doNotContact->createDncRecord($entity, $channel, $reason, $comments);
                } else {
                    // Add DNC record to existing contact
                    $doNotContact->addDncForContact($entity->getId(), $channel, $reason, $comments, false);
                }
            }
            unset($parameters['doNotContact']);
        }

        if (!empty($parameters['frequencyRules'])) {
            $viewParameters = [];
            $data           = $this->getFrequencyRuleFormData($entity, null, null, false, $parameters['frequencyRules']);

            if (!$frequencyForm = $this->getFrequencyRuleForm($entity, $viewParameters, $data)) {
                $formErrors = $this->getFormErrorMessages($frequencyForm);
                $msg        = $this->getFormErrorMessage($formErrors);

                if (!$msg) {
                    $msg = $this->translator->trans('mautic.core.error.badrequest', [], 'flashes');
                }

                return $this->returnError($msg, Response::HTTP_BAD_REQUEST, $formErrors);
            }

            unset($parameters['frequencyRules']);
        }

        $isPostOrPatch = 'POST' === $this->request->getMethod() || 'PATCH' === $this->request->getMethod();
        $this->setCustomFieldValues($entity, $form, $parameters, $isPostOrPatch);
    }

    /**
     * Helper method to be used in FrequencyRuleTrait.
     *
     * @param Form $form
     *
     * @return bool
     */
    protected function isFormCancelled($form = null)
    {
        return false;
    }

    /**
     * Helper method to be used in FrequencyRuleTrait.
     *
     * @param array $data
     *
     * @return bool
     */
    protected function isFormValid(Form $form, array $data = null)
    {
        $form->submit($data, 'PATCH' !== $this->request->getMethod());

        return $form->isSubmitted() && $form->isValid();
    }
}
