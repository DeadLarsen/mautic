<?php

namespace Mautic\AssetBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Form\Type\DateRangeType;
use Mautic\CoreBundle\Helper\FileHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends FormController
{
    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
        $model = $this->getModel('asset');

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted([
            'asset:assets:viewown',
            'asset:assets:viewother',
            'asset:assets:create',
            'asset:assets:editown',
            'asset:assets:editother',
            'asset:assets:deleteown',
            'asset:assets:deleteother',
            'asset:assets:publishown',
            'asset:assets:publishother',
        ], 'RETURN_ARRAY');

        if (!$permissions['asset:assets:viewown'] && !$permissions['asset:assets:viewother']) {
            return $this->accessDenied();
        }

        $this->setListFilters();

        //set limits
        $limit = $this->get('session')->get('mautic.asset.limit', $this->get('mautic.helper.core_parameters')->get('default_assetlimit'));
        $start = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $this->get('session')->get('mautic.asset.filter', ''));
        $this->get('session')->set('mautic.asset.filter', $search);

        $filter = ['string' => $search, 'force' => []];

        if (!$permissions['asset:assets:viewother']) {
            $filter['force'][] =
                ['column' => 'a.createdBy', 'expr' => 'eq', 'value' => $this->user->getId()];
        }

        $orderBy    = $this->get('session')->get('mautic.asset.orderby', 'a.dateModified');
        $orderByDir = $this->get('session')->get('mautic.asset.orderbydir', $this->getDefaultOrderDirection());

        $assets = $model->getEntities(
            [
                'start'      => $start,
                'limit'      => $limit,
                'filter'     => $filter,
                'orderBy'    => $orderBy,
                'orderByDir' => $orderByDir,
            ]
        );

        $count = count($assets);
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current asset so redirect to the last asset
            if (1 === $count) {
                $lastPage = 1;
            } else {
                $lastPage = (ceil($count / $limit)) ?: 1;
            }
            $this->get('session')->set('mautic.asset.asset', $lastPage);
            $returnUrl = $this->generateUrl('mautic_asset_index', ['page' => $lastPage]);

            return $this->postActionRedirect([
                'returnUrl'       => $returnUrl,
                'viewParameters'  => ['asset' => $lastPage],
                'contentTemplate' => 'Mautic\AssetBundle\Controller\AssetController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_asset_index',
                    'mauticContent' => 'asset',
                ],
            ]);
        }

        //set what asset currently on so that we can return here after form submission/cancellation
        $this->get('session')->set('mautic.asset.page', $page);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        //retrieve a list of categories
        $categories = $this->getModel('asset')->getLookupResults('category', '', 0);

        return $this->delegateView([
            'viewParameters' => [
                'searchValue' => $search,
                'items'       => $assets,
                'categories'  => $categories,
                'limit'       => $limit,
                'permissions' => $permissions,
                'model'       => $model,
                'tmpl'        => $tmpl,
                'page'        => $page,
                'security'    => $this->get('mautic.security'),
            ],
            'contentTemplate' => 'MauticAssetBundle:Asset:list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_asset_index',
                'mauticContent' => 'asset',
                'route'         => $this->generateUrl('mautic_asset_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * Loads a specific form into the detailed panel.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction($objectId)
    {
        $model       = $this->getModel('asset');
        $security    = $this->get('mautic.security');
        $activeAsset = $model->getEntity($objectId);

        //set the asset we came from
        $page = $this->get('session')->get('mautic.asset.page', 1);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'details') : 'details';

        // Init the date range filter form
        $dateRangeValues = $this->request->get('daterange', []);
        $action          = $this->generateUrl('mautic_asset_action', ['objectAction' => 'view', 'objectId' => $objectId]);
        $dateRangeForm   = $this->get('form.factory')->create(DateRangeType::class, $dateRangeValues, ['action' => $action]);

        if (null === $activeAsset) {
            //set the return URL
            $returnUrl = $this->generateUrl('mautic_asset_index', ['page' => $page]);

            return $this->postActionRedirect([
                'returnUrl'       => $returnUrl,
                'viewParameters'  => ['page' => $page],
                'contentTemplate' => 'Mautic\AssetBundle\Controller\AssetController::indexAction',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_asset_index',
                    'mauticContent' => 'asset',
                ],
                'flashes' => [
                    [
                        'type'    => 'error',
                        'msg'     => 'mautic.asset.asset.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ],
                ],
            ]);
        } elseif (!$this->get('mautic.security')->hasEntityAccess('asset:assets:viewown', 'asset:assets:viewother', $activeAsset->getCreatedBy())) {
            return $this->accessDenied();
        }

        // Audit Log
        $logs = $this->getModel('core.auditlog')->getLogForObject('asset', $activeAsset->getId(), $activeAsset->getDateAdded());

        $templ = $this->request->get('templ') ?? 'twig';

        return $this->delegateView([
            'returnUrl'      => $action,
            'viewParameters' => [
                'activeAsset' => $activeAsset,
                'tmpl'        => $tmpl,
                'permissions' => $security->isGranted([
                    'asset:assets:viewown',
                    'asset:assets:viewother',
                    'asset:assets:create',
                    'asset:assets:editown',
                    'asset:assets:editother',
                    'asset:assets:deleteown',
                    'asset:assets:deleteother',
                    'asset:assets:publishown',
                    'asset:assets:publishother',
                ], 'RETURN_ARRAY'),
                'stats' => [
                    'downloads' => [
                        'total'     => $activeAsset->getDownloadCount(),
                        'unique'    => $activeAsset->getUniqueDownloadCount(),
                        'timeStats' => $model->getDownloadsLineChartData(
                            null,
                            new \DateTime($dateRangeForm->get('date_from')->getData()),
                            new \DateTime($dateRangeForm->get('date_to')->getData()),
                            null,
                            ['asset_id' => $activeAsset->getId()]
                        ),
                    ],
                ],
                'security'         => $security,
                'assetDownloadUrl' => $model->generateUrl($activeAsset, true),
                'logs'             => $logs,
                'dateRangeForm'    => $dateRangeForm->createView(),
            ],
            'contentTemplate' => 'MauticAssetBundle:Asset:'.$tmpl.'.html.'.$templ,
            'passthroughVars' => [
                'activeLink'    => '#mautic_asset_index',
                'mauticContent' => 'asset',
            ],
        ]);
    }

    /**
     * Show a preview of the file.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function previewAction($objectId)
    {
        /** @var \Mautic\AssetBundle\Model\AssetModel $model */
        $model       = $this->getModel('asset');
        $activeAsset = $model->getEntity($objectId);

        if (null === $activeAsset || !$this->get('mautic.security')->hasEntityAccess('asset:assets:viewown', 'asset:assets:viewother', $activeAsset->getCreatedBy())) {
            return $this->modalAccessDenied();
        }

        $download = $this->request->query->get('download', 0);

        // Display the file directly in the browser by default
        $stream   = $this->request->query->get('stream', '1');

        if ('1' === $download || '1' === $stream) {
            try {
                //set the uploadDir
                $activeAsset->setUploadDir($this->get('mautic.helper.core_parameters')->get('upload_dir'));
                $contents = $activeAsset->getFileContents();
            } catch (\Exception $e) {
                return $this->notFound();
            }

            $response = new Response();
            $response->headers->set('Content-Type', $activeAsset->getFileMimeType());
            if ('1' === $download) {
                $response->headers->set('Content-Disposition', 'attachment;filename="'.$activeAsset->getOriginalFileName());
            }
            $response->setContent($contents);

            return $response;
        }

        return $this->delegateView([
            'viewParameters' => [
                'activeAsset'      => $activeAsset,
                'assetDownloadUrl' => $model->generateUrl($activeAsset),
            ],
            'contentTemplate' => 'MauticAssetBundle:Asset:preview.html.php',
            'passthroughVars' => [
                'route' => false,
            ],
        ]);
    }

    /**
     * Generates new form and processes post data.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction($entity = null)
    {
        /** @var \Mautic\AssetBundle\Model\AssetModel $model */
        $model = $this->getModel('asset');

        /** @var \Mautic\AssetBundle\Entity\Asset $entity */
        if (null == $entity) {
            $entity = $model->getEntity();
        }

        $entity->setMaxSize(FileHelper::convertMegabytesToBytes($this->get('mautic.helper.core_parameters')->get('max_size')));

        $method  = $this->request->getMethod();
        $session = $this->get('session');

        if (!$this->get('mautic.security')->isGranted('asset:assets:create')) {
            return $this->accessDenied();
        }

        $maxSize    = $model->getMaxUploadSize();
        $extensions = '.'.implode(', .', $this->get('mautic.helper.core_parameters')->get('allowed_extensions'));

        $maxSizeError = $this->get('translator')->trans('mautic.asset.asset.error.file.size', [
            '%fileSize%' => '{{filesize}}',
            '%maxSize%'  => '{{maxFilesize}}',
        ], 'validators');

        $extensionError = $this->get('translator')->trans('mautic.asset.asset.error.file.extension.js', [
            '%extensions%' => $extensions,
        ], 'validators');

        // Create temporary asset ID
        $asset  = $this->request->request->get('asset', []);
        $tempId = 'POST' === $method ? ($asset['tempId'] ?? '') : uniqid('tmp_');
        $entity->setTempId($tempId);

        // Set the page we came from
        $page   = $session->get('mautic.asset.page', 1);
        $action = $this->generateUrl('mautic_asset_action', ['objectAction' => 'new']);

        // Get upload folder
        $uploaderHelper = $this->container->get('oneup_uploader.templating.uploader_helper');
        $uploadEndpoint = $uploaderHelper->endpoint('asset');

        //create the form
        $form = $model->createForm($entity, $this->get('form.factory'), $action);

        ///Check for a submitted form and process it
        if ('POST' == $method) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $entity->setUploadDir($this->get('mautic.helper.core_parameters')->get('upload_dir'));
                    $entity->preUpload();
                    $entity->upload();
                    $entity->setDateModified(new \DateTime());
                    //form is valid so process the data
                    $model->saveEntity($entity);

                    //remove the asset from request
                    $this->request->files->remove('asset');

                    $this->addFlash('mautic.core.notice.created', [
                        '%name%'      => $entity->getTitle(),
                        '%menu_link%' => 'mautic_asset_index',
                        '%url%'       => $this->generateUrl('mautic_asset_action', [
                            'objectAction' => 'edit',
                            'objectId'     => $entity->getId(),
                        ]),
                    ]);

                    if (!$form->get('buttons')->get('save')->isClicked()) {
                        //return edit view so that all the session stuff is loaded
                        return $this->editAction($entity->getId(), true);
                    }

                    $viewParameters = [
                        'objectAction' => 'view',
                        'objectId'     => $entity->getId(),
                    ];
                    $returnUrl = $this->generateUrl('mautic_asset_action', $viewParameters);
                    $template  = 'Mautic\AssetBundle\Controller\AssetController::viewAction';
                }
            } else {
                $viewParameters = ['page' => $page];
                $returnUrl      = $this->generateUrl('mautic_asset_index', $viewParameters);
                $template       = 'Mautic\AssetBundle\Controller\AssetController::indexAction';
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                return $this->postActionRedirect([
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => $template,
                    'passthroughVars' => [
                        'activeLink'    => 'mautic_asset_index',
                        'mauticContent' => 'asset',
                    ],
                ]);
            }
        }

        // Check for integrations to cloud providers
        /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $integrationHelper */
        $integrationHelper = $this->factory->getHelper('integration');

        $integrations = $integrationHelper->getIntegrationObjects(null, ['cloud_storage']);

        return $this->delegateView([
            'viewParameters' => [
                'form'             => $form->createView(),
                'activeAsset'      => $entity,
                'assetDownloadUrl' => $model->generateUrl($entity),
                'integrations'     => $integrations,
                'startOnLocal'     => $entity->isLocal(),
                'uploadEndpoint'   => $uploadEndpoint,
                'maxSize'          => $maxSize,
                'maxSizeError'     => $maxSizeError,
                'extensions'       => $extensions,
                'extensionError'   => $extensionError,
            ],
            'contentTemplate' => 'MauticAssetBundle:Asset:form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_asset_index',
                'mauticContent' => 'asset',
                'route'         => $this->generateUrl('mautic_asset_action', [
                    'objectAction' => 'new',
                ]),
            ],
        ]);
    }

    /**
     * Generates edit form and processes post data.
     *
     * @param int  $objectId
     * @param bool $ignorePost
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction($objectId, $ignorePost = false)
    {
        /** @var \Mautic\AssetBundle\Model\AssetModel $model */
        $model  = $this->getModel('asset');
        $entity = $model->getEntity($objectId);

        $entity->setMaxSize(FileHelper::convertMegabytesToBytes($this->get('mautic.helper.core_parameters')->get('max_size')));

        $session    = $this->get('session');
        $page       = $session->get('mautic.asset.page', 1);
        $method     = $this->request->getMethod();
        $maxSize    = $model->getMaxUploadSize();
        $extensions = '.'.implode(', .', $this->get('mautic.helper.core_parameters')->get('allowed_extensions'));

        $maxSizeError = $this->get('translator')->trans('mautic.asset.asset.error.file.size', [
            '%fileSize%' => '{{filesize}}',
            '%maxSize%'  => '{{maxFilesize}}',
        ], 'validators');

        $extensionError = $this->get('translator')->trans('mautic.asset.asset.error.file.extension.js', [
            '%extensions%' => $extensions,
        ], 'validators');

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_asset_index', ['page' => $page]);

        // Get upload folder
        $uploaderHelper = $this->container->get('oneup_uploader.templating.uploader_helper');
        $uploadEndpoint = $uploaderHelper->endpoint('asset');

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\AssetBundle\Controller\AssetController::indexAction',
            'passthroughVars' => [
                'activeLink'    => 'mautic_asset_index',
                'mauticContent' => 'asset',
            ],
        ];

        //not found
        if (null === $entity) {
            return $this->postActionRedirect(
                array_merge($postActionVars, [
                    'flashes' => [
                        [
                            'type'    => 'error',
                            'msg'     => 'mautic.asset.asset.error.notfound',
                            'msgVars' => ['%id%' => $objectId],
                        ],
                    ],
                ])
            );
        } elseif (!$this->get('mautic.security')->hasEntityAccess(
            'asset:assets:viewown', 'asset:assets:viewother', $entity->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        } elseif ($model->isLocked($entity)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $entity, 'asset.asset');
        }

        // Create temporary asset ID
        $asset  = $this->request->request->get('asset', []);
        $tempId = 'POST' === $method ? ($asset['tempId'] ?? '') : uniqid('tmp_');
        $entity->setTempId($tempId);

        //Create the form
        $action = $this->generateUrl('mautic_asset_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $form   = $model->createForm($entity, $this->get('form.factory'), $action);

        ///Check for a submitted form and process it
        if (!$ignorePost && 'POST' == $method) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $entity->setUploadDir($this->get('mautic.helper.core_parameters')->get('upload_dir'));
                    $entity->preUpload();
                    $entity->upload();

                    //form is valid so process the data
                    $model->saveEntity($entity, $form->get('buttons')->get('save')->isClicked());

                    //remove the asset from request
                    $this->request->files->remove('asset');

                    $this->addFlash('mautic.core.notice.updated', [
                        '%name%'      => $entity->getTitle(),
                        '%menu_link%' => 'mautic_asset_index',
                        '%url%'       => $this->generateUrl('mautic_asset_action', [
                            'objectAction' => 'edit',
                            'objectId'     => $entity->getId(),
                        ]),
                    ]);

                    $returnUrl = $this->generateUrl('mautic_asset_action', [
                        'objectAction' => 'view',
                        'objectId'     => $entity->getId(),
                    ]);
                    $viewParams = ['objectId' => $entity->getId()];
                    $template   = 'Mautic\AssetBundle\Controller\AssetController::viewAction';
                }
            } else {
                //clear any modified content
                $session->remove('mautic.asestbuilder.'.$objectId.'.content');
                //unlock the entity
                $model->unlockEntity($entity);

                $returnUrl  = $this->generateUrl('mautic_asset_index', ['page' => $page]);
                $viewParams = ['page' => $page];
                $template   = 'Mautic\AssetBundle\Controller\AssetController::indexAction';
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                return $this->postActionRedirect(
                    array_merge($postActionVars, [
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParams,
                        'contentTemplate' => $template,
                    ])
                );
            }
        } else {
            //lock the entity
            $model->lockEntity($entity);
        }

        // Check for integrations to cloud providers
        /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $integrationHelper */
        $integrationHelper = $this->factory->getHelper('integration');

        $integrations = $integrationHelper->getIntegrationObjects(null, ['cloud_storage']);

        return $this->delegateView([
            'viewParameters' => [
                'form'             => $form->createView(),
                'activeAsset'      => $entity,
                'assetDownloadUrl' => $model->generateUrl($entity),
                'integrations'     => $integrations,
                'startOnLocal'     => $entity->isLocal(),
                'uploadEndpoint'   => $uploadEndpoint,
                'maxSize'          => $maxSize,
                'maxSizeError'     => $maxSizeError,
                'extensions'       => $extensions,
                'extensionError'   => $extensionError,
            ],
            'contentTemplate' => 'MauticAssetBundle:Asset:form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_asset_index',
                'mauticContent' => 'asset',
                'route'         => $this->generateUrl('mautic_asset_action', [
                    'objectAction' => 'edit',
                    'objectId'     => $entity->getId(),
                ]),
            ],
        ]);
    }

    /**
     * Clone an entity.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function cloneAction($objectId)
    {
        /** @var \Mautic\AssetBundle\Model\AssetModel $model */
        $model  = $this->getModel('asset');
        $entity = $model->getEntity($objectId);

        if (null != $entity) {
            if (!$this->get('mautic.security')->isGranted('asset:assets:create') ||
                !$this->get('mautic.security')->hasEntityAccess(
                    'asset:assets:viewown', 'asset:assets:viewother', $entity->getCreatedBy()
                )
            ) {
                return $this->accessDenied();
            }

            $clone = clone $entity;
            $clone->setDownloadCount(0);
            $clone->setUniqueDownloadCount(0);
            $clone->setRevision(0);
            $clone->setIsPublished(false);
        }

        return $this->newAction($clone);
    }

    /**
     * Deletes the entity.
     *
     * @param int $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction($objectId)
    {
        $page      = $this->get('session')->get('mautic.asset.page', 1);
        $returnUrl = $this->generateUrl('mautic_asset_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\AssetBundle\Controller\AssetController::indexAction',
            'passthroughVars' => [
                'activeLink'    => 'mautic_asset_index',
                'mauticContent' => 'asset',
            ],
        ];

        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\AssetBundle\Model\AssetModel $model */
            $model  = $this->getModel('asset');
            $entity = $model->getEntity($objectId);

            if (null === $entity) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'mautic.asset.asset.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif (!$this->get('mautic.security')->hasEntityAccess(
                'asset:assets:deleteown',
                'asset:assets:deleteother',
                $entity->getCreatedBy()
            )
            ) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'asset.asset');
            }

            $entity->removeUpload();
            $model->deleteEntity($entity);

            $flashes[] = [
                'type'    => 'notice',
                'msg'     => 'mautic.core.notice.deleted',
                'msgVars' => [
                    '%name%' => $entity->getTitle(),
                    '%id%'   => $objectId,
                ],
            ];
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge($postActionVars, [
                'flashes' => $flashes,
            ])
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function batchDeleteAction()
    {
        $page      = $this->get('session')->get('mautic.asset.page', 1);
        $returnUrl = $this->generateUrl('mautic_asset_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\AssetBundle\Controller\AssetController::indexAction',
            'passthroughVars' => [
                'activeLink'    => 'mautic_asset_index',
                'mauticContent' => 'asset',
            ],
        ];

        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\AssetBundle\Model\AssetModel $model */
            $model     = $this->getModel('asset');
            $ids       = json_decode($this->request->query->get('ids', '{}'));
            $deleteIds = [];

            // Loop over the IDs to perform access checks pre-delete
            foreach ($ids as $objectId) {
                $entity = $model->getEntity($objectId);

                if (null === $entity) {
                    $flashes[] = [
                        'type'    => 'error',
                        'msg'     => 'mautic.asset.asset.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ];
                } elseif (!$this->get('mautic.security')->hasEntityAccess(
                    'asset:assets:deleteown', 'asset:assets:deleteother', $entity->getCreatedBy()
                )
                ) {
                    $flashes[] = $this->accessDenied(true);
                } elseif ($model->isLocked($entity)) {
                    $flashes[] = $this->isLocked($postActionVars, $entity, 'asset', true);
                } else {
                    $deleteIds[] = $objectId;
                }
            }

            // Delete everything we are able to
            if (!empty($deleteIds)) {
                $entities = $model->deleteEntities($deleteIds);

                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.asset.asset.notice.batch_deleted',
                    'msgVars' => [
                        '%count%' => count($entities),
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge($postActionVars, [
                'flashes' => $flashes,
            ])
        );
    }

    /**
     * Renders the container for the remote file browser.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function remoteAction()
    {
        // Check for integrations to cloud providers
        /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $integrationHelper */
        $integrationHelper = $this->factory->getHelper('integration');

        $integrations = $integrationHelper->getIntegrationObjects(null, ['cloud_storage']);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        return $this->delegateView([
            'viewParameters' => [
                'integrations' => $integrations,
                'tmpl'         => $tmpl,
            ],
            'contentTemplate' => 'MauticAssetBundle:Remote:browse.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_asset_index',
                'mauticContent' => 'asset',
                'route'         => $this->generateUrl('mautic_asset_index', ['page' => $this->get('session')->get('mautic.asset.page', 1)]),
            ],
        ]);
    }

    public function getModelName(): string
    {
        return 'asset';
    }

    protected function getDefaultOrderDirection(): string
    {
        return 'DESC';
    }
}
