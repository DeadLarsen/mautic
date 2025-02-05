<?php

namespace Mautic\CoreBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\InputHelper;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

class FileApiController extends CommonApiController
{
    /**
     * Holds array of allowed file extensions.
     *
     * @var array
     */
    protected $allowedExtensions = [];

    public function initialize(ControllerEvent $event)
    {
        $this->entityNameOne     = 'file';
        $this->entityNameMulti   = 'files';
        $this->allowedExtensions = $this->get('mautic.helper.core_parameters')->get('allowed_extensions');

        parent::initialize($event);
    }

    /**
     * Uploads a file.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function createAction($dir)
    {
        try {
            $path = $this->getAbsolutePath($dir, true);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        $response = [$this->entityNameOne => []];
        if ($this->request->files) {
            foreach ($this->request->files as $file) {
                $extension = $file->guessExtension() ? $file->guessExtension() : $file->getClientOriginalExtension();
                if (in_array($extension, $this->allowedExtensions)) {
                    $fileName = md5(uniqid()).'.'.$extension;
                    $moved    = $file->move($path, $fileName);

                    if ('images' === substr($dir, 0, 6)) {
                        $response[$this->entityNameOne]['link'] = $this->getMediaUrl().'/'.$fileName;
                    }

                    $response[$this->entityNameOne]['name'] = $fileName;
                } else {
                    return $this->returnError('The uploaded file can have only these extensions: '.implode(',', $this->allowedExtensions).'.', Response::HTTP_NOT_ACCEPTABLE);
                }
            }
        } else {
            return $this->returnError('File was not found in the request.', Response::HTTP_NOT_ACCEPTABLE);
        }

        $view = $this->view($response);

        return $this->handleView($view);
    }

    /**
     * List the files in /media directory.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function listAction($dir)
    {
        try {
            $filePath = $this->getAbsolutePath($dir);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        $fnames = scandir($filePath);

        if (is_array($fnames)) {
            foreach ($fnames as $key => $name) {
                // remove hidden files
                if ('.' === substr($name, 0, 1)) {
                    unset($fnames[$key]);
                }
            }
        } else {
            return $this->returnError(ucfirst($dir).' dir is not readable');
        }

        $view = $this->view([$this->entityNameMulti => $fnames]);

        return $this->handleView($view);
    }

    /**
     * Delete a file from /media directory.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function deleteAction($dir, $file)
    {
        $response = ['success' => false];

        try {
            $filePath = $this->getAbsolutePath($dir).'/'.basename($file);
        } catch (\Exception $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_NOT_ACCEPTABLE);
        }

        if (!file_exists($filePath)) {
            return $this->returnError('File does not exist', Response::HTTP_NOT_FOUND);
        } elseif (!is_writable($filePath)) {
            return $this->returnError('File is not writable');
        } else {
            unlink($filePath);
            $response['success'] = true;
        }

        $view = $this->view($response);

        return $this->handleView($view);
    }

    /**
     * Get the Media directory full file system path.
     *
     * @param string $dir
     * @param bool   $createDir
     *
     * @return string
     */
    protected function getAbsolutePath($dir, $createDir = false)
    {
        try {
            $possibleDirs = ['assets', 'images'];
            $dir          = InputHelper::alphanum($dir, true, false, ['_', '.']);
            $subdir       = trim(InputHelper::alphanum($this->request->get('subdir', ''), true, false, ['/']));

            // Dots in the dir name are slashes
            if (false !== strpos($dir, '.') && !$subdir) {
                $dirs = explode('.', $dir);
                $dir  = $dirs[0];
                unset($dirs[0]);
                $subdir = implode('/', $dirs);
            }

            if (!in_array($dir, $possibleDirs)) {
                throw new \InvalidArgumentException($dir.' not found. Only '.implode(' or ', $possibleDirs).' options are possible.');
            }

            if ('images' === $dir) {
                $absoluteDir = realpath($this->get('mautic.helper.paths')->getSystemPath($dir, true));
            } elseif ('assets' === $dir) {
                $absoluteDir = realpath($this->get('mautic.helper.core_parameters')->get('upload_dir'));
            }

            if (false === $absoluteDir) {
                throw new \InvalidArgumentException($dir.' dir does not exist', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if (false === is_writable($absoluteDir)) {
                throw new \InvalidArgumentException($dir.' dir is not writable', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $path = $absoluteDir.'/'.$subdir;

            if (!file_exists($path)) {
                if ($createDir) {
                    if (false === mkdir($path)) {
                        throw new \InvalidArgumentException($dir.'/'.$subdir.' subdirectory could not be created.', Response::HTTP_INTERNAL_SERVER_ERROR);
                    }
                } else {
                    throw new \InvalidArgumentException($subdir.' doesn\'t exist in the '.$dir.' dir.');
                }
            }

            return $path;
        } catch (\Exception $e) {
            $this->get('monolog.logger.mautic')->error($e->getMessage(), ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * Get the Media directory full file system path.
     *
     * @return string
     */
    protected function getMediaUrl()
    {
        return $this->request->getScheme().'://'
            .$this->request->getHttpHost()
            .':'.$this->request->getPort()
            .$this->request->getBasePath().'/'
            .$this->get('mautic.helper.core_parameters')->get('image_path');
    }
}
