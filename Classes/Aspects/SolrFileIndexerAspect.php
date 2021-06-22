<?php

namespace BeechIt\FalSecuredownload\Aspects;

/**
 * This source file is proprietary property of Beech Applications B.V.
 * Date: 12-03-2015 11:07
 * All code (c) Beech Applications B.V. all rights reserved
 */

use BeechIt\FalSecuredownload\Security\CheckPermissions;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueItemRepository;

//for debugging
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Class SolrFalAspect
 */
class SolrFileIndexerAspect implements SingletonInterface, LoggerAwareInterface
{   use LoggerAwareTrait;
    
    /**
     * @var debug
     */
    protected $debug = false;
      
    /**
     * @var CheckPermissions
     */
    protected $checkPermissionsService;

    /**
     * @var PublicUrlAspect
     */
    protected $publicUrlAspect;
    
    /**
     * @var QueueItemRepository
     */
    protected $queueItemRepository;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->checkPermissionsService = GeneralUtility::makeInstance(CheckPermissions::class);
        $this->publicUrlAspect = GeneralUtility::makeInstance(PublicUrlAspect::class);
        $this->queueItemRepository = GeneralUtility::makeInstance(QueueItemRepository::class);
    }

    /**
     * Add correct fe_group info and public_url
     *
     * @param Document $document
     * @param content
     */
    public function postAddContent(Document $document, $content)
    {           
      //item UID
      $itemUid = (int) $document->getUid();
      $queueItem = $this->queueItemRepository->findItemsByItemTypeAndItemUid('sys_file_metadata', $itemUid);
      
      //file UID
      if(!is_null($queueItem) && is_array($queueItem) && array_key_exists(0, $queueItem)) {
        $fileUid = $queueItem[0]->getRecord()['file']; 
      }

      if(isset($fileUid)) {
        try {
          $resourceFactory = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance();
          $file = $resourceFactory->getFileObject($fileUid);
        } 
        catch (\TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException $fileNotFoundException) {
          $this->logger->log(\TYPO3\CMS\Core\Log\LogLevel::CRITICAL, 'file not found: '.$document->getTitle_stringS(), []);
                  
          // Nothing to be done if file not found
          return [null, null];
        }
        
        //if file is in recycler ignore
        if(strpos($file->getIdentifier(), '_recycler_') !== false) {
           $this->logger->log(\TYPO3\CMS\Core\Log\LogLevel::CRITICAL, 'recycler??', []);
          return [$document, $content];
        }
        
        //Set pemission
        $resourcePermissions = 'r:0';
        if(!is_null($file) && !$file->getStorage()->isPublic()) {
          $resourcePermissions = $this->checkPermissionsService->getPermissions($file);
          
          if(is_array($resourcePermissions)) $resourcePermissions = implode(',', $resourcePermissions);
          if(strpos($resourcePermissions, 'r:') === false) $resourcePermissions = 'r:'.$resourcePermissions;
        }
        $document->setField('access', $resourcePermissions);
       
        // Re-generate public url
        $this->publicUrlAspect->setEnabled(false);
        $public_url = $file->getPublicUrl();       
        $this->publicUrlAspect->setEnabled(true);
        $document->setField('url', $public_url);
        $document->setField('public_url', $public_url);
        
        if($this->debug) {
          $this->logger->log(\TYPO3\CMS\Core\Log\LogLevel::CRITICAL, 'public_url: '.$public_url, []);
          $this->logger->log(\TYPO3\CMS\Core\Log\LogLevel::CRITICAL, 'access: ['.$resourcePermissions.']', []);
          $this->logger->log(\TYPO3\CMS\Core\Log\LogLevel::CRITICAL, 'identifier: '.$file->getIdentifier().PHP_EOL.PHP_EOL, []);
        }
      }
        
      return [$document, $content];
    }
}
