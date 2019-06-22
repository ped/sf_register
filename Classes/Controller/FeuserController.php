<?php
namespace Evoweb\SfRegister\Controller;

/***************************************************************
 * Copyright notice
 *
 * (c) 2011-17 Sebastian Fischer <typo3@evoweb.de>
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Evoweb\SfRegister\Domain\Model\FrontendUser;
use Evoweb\SfRegister\Domain\Repository\FrontendUserGroupRepository;
use Evoweb\SfRegister\Domain\Repository\FrontendUserRepository;
use Evoweb\SfRegister\Property\TypeConverter\DateTimeConverter;
use Evoweb\SfRegister\Property\TypeConverter\UploadedFileReferenceConverter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration;
use TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter;

/**
 * An frontend user controller
 */
class FeuserController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * User repository
     *
     * @var FrontendUserRepository
     */
    protected $userRepository;

    /**
     * Usergroup repository
     *
     * @var FrontendUserGroupRepository
     */
    protected $userGroupRepository;

    /**
     * Signal slot dispatcher
     *
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * File service
     *
     * @var \Evoweb\SfRegister\Services\File
     */
    protected $fileService;

    /**
     * The current view, as resolved by resolveView()
     *
     * @var \TYPO3\CMS\Fluid\View\TemplateView
     * @api
     */
    protected $view;

    /**
     * The current request.
     *
     * @var \TYPO3\CMS\Extbase\Mvc\Web\Request
     * @api
     */
    protected $request;

    /**
     * Active if autologgin was set.
     *
     * Used to define of on page redirect an additional
     * query parameter should be set.
     *
     * @var bool
     */
    protected $autoLoginTriggered = false;


    /**
     * @param FrontendUserRepository $userRepository
     *
     * @return void
     */
    public function injectUserRepository(FrontendUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @param FrontendUserGroupRepository $userGroupRepository
     *
     * @return void
     */
    public function injectUserGroupRepository(FrontendUserGroupRepository $userGroupRepository)
    {
        $this->userGroupRepository = $userGroupRepository;
    }

    /**
     * @param \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher
     *
     * @return void
     */
    public function injectSignalSlotDispatcher(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher$signalSlotDispatcher)
    {
        $this->signalSlotDispatcher = $signalSlotDispatcher;
    }


    /**
     * Disable Flashmessages
     *
     * @return bool
     */
    protected function getErrorFlashMessage(): bool
    {
        return false;
    }

    /**
     * Initialize all actions
     *
     * @return void
     */
    protected function initializeAction()
    {
        $this->fileService = $this->objectManager->get(\Evoweb\SfRegister\Services\File::class);
        $this->setTypeConverter();

        if ($this->settings['processInitializeActionSignal']) {
            $this->signalSlotDispatcher->dispatch(
                __CLASS__,
                __FUNCTION__,
                [
                    'controller' => $this,
                    'settings' => $this->settings,
                ]
            );
        }

        if ($this->request->getControllerActionName() != 'removeImage'
            && $this->request->hasArgument('removeImage')
            && $this->request->getArgument('removeImage')
        ) {
            $this->forward('removeImage');
        }
    }

    /**
     * @return void
     */
    protected function setTypeConverter()
    {
        $argumentName = 'user';
        if ($this->request->hasArgument($argumentName)) {
            /** @var PropertyMappingConfiguration $configuration */
            $configuration = $this->arguments[$argumentName]->getPropertyMappingConfiguration();
            $this->getPropertyMappingConfiguration(
                $configuration,
                $this->request->getArgument('user')
            );
        }
    }

    /**
     * @param PropertyMappingConfiguration $configuration
     * @param array $userData
     *
     * @return PropertyMappingConfiguration
     */
    protected function getPropertyMappingConfiguration(
        PropertyMappingConfiguration $configuration = null,
        $userData = []
    ) {
        if (is_null($configuration)) {
            $configuration = $this->objectManager->get(
                PropertyMappingConfiguration::class
            );
        }

        $configuration->allowAllProperties();
        $configuration->forProperty('usergroup')->allowAllProperties();
        $configuration->forProperty('image')->allowAllProperties();
        $configuration->setTypeConverterOption(
            PersistentObjectConverter::class,
            PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED,
            true
        );

        $folder = $this->fileService->getTempFolder();
        $uploadConfiguration = [
            UploadedFileReferenceConverter::CONFIGURATION_ALLOWED_FILE_EXTENSIONS =>
                $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'],
            UploadedFileReferenceConverter::CONFIGURATION_UPLOAD_FOLDER =>
                $folder->getStorage()->getUid() . ':' . $folder->getIdentifier(),
        ];

        $configuration->forProperty('image.0')
            ->setTypeConverterOptions(
                UploadedFileReferenceConverter::class,
                $uploadConfiguration
            );

        $configuration->forProperty('dateOfBirth')
            ->setTypeConverterOptions(
                DateTimeConverter::class,
                [
                    DateTimeConverter::CONFIGURATION_USER_DATA => $userData,
                ]
            );

        return $configuration;
    }

    /**
     * Inject an view object to be able to set templateRootPath from flexform
     *
     * @param \TYPO3\CMS\Extbase\Mvc\View\ViewInterface $view
     *
     * @return void
     */
    protected function initializeView(\TYPO3\CMS\Extbase\Mvc\View\ViewInterface $view)
    {
        if (isset($this->settings['templateRootPath']) && !empty($this->settings['templateRootPath'])) {
            $templateRootPath = GeneralUtility::getFileAbsFileName($this->settings['templateRootPath']);
            if (GeneralUtility::isAllowedAbsPath($templateRootPath)) {
                $this->view->setTemplateRootPaths([$templateRootPath]);
            }
        }
    }


    /**
     * Proxy action
     *
     * @param FrontendUser $user
     *
     * @return void
     * @validate $user Evoweb.SfRegister:User
     */
    public function proxyAction(FrontendUser $user)
    {
        $action = 'save';

        if ($this->request->hasArgument('form')) {
            $action = 'form';
        }

        $this->forward($action);
    }

    /**
     * Remove an image and forward to the action where it was called
     *
     * @param FrontendUser $user
     *
     * @return void
     * @ignorevalidation $user
     */
    protected function removeImageAction(FrontendUser $user)
    {
        /** @var \TYPO3\CMS\Extbase\Domain\Model\FileReference $image */
        $user->getImage()->rewind();
        $image = $user->getImage()->current();

        $this->removeImageFromUserAndRequest($user, $image);

        $this->request->setArgument('removeImage', false);

        $referrer = $this->request->getReferringRequest();
        if ($referrer !== null) {
            $this->forward(
                $referrer->getControllerActionName(),
                $referrer->getControllerName(),
                $referrer->getControllerExtensionName(),
                $this->request->getArguments()
            );
        }
    }

    /**
     * Remove an image from user object and request object
     *
     * @param FrontendUser $user
     * @param FileReference $image
     *
     * @return FrontendUser
     */
    protected function removeImageFromUserAndRequest(FrontendUser $user, $image = null): FrontendUser
    {
        if ($user->getUid() !== null) {
            /** @var FrontendUser $localUser */
            $localUser = $this->userRepository->findByUid($user->getUid());
            $localUser->removeImage($image);
            $this->userRepository->update($localUser);

            $this->persistAll();
        }

        if (!is_null($image)) {
            $file = $image->getOriginalResource();
            $file->getStorage()->deleteFile($file);
        }

        $user->emptyImage();

        /** @var array $requestUser */
        $requestUser = $this->request->getArgument('user');
        $requestUser['image'] = $user->getImage();
        $this->request->setArgument('user', $requestUser);

        return $user;
    }

    /**
     * Encrypt the password
     *
     * @param string $password
     * @param array $settings
     *
     * @return string
     */
    public static function encryptPassword($password, $settings): string
    {
        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('saltedpasswords')
            && \TYPO3\CMS\Saltedpasswords\Utility\SaltedPasswordsUtility::isUsageEnabled('FE')
        ) {
            $saltObject = \TYPO3\CMS\Saltedpasswords\Salt\SaltFactory::getSaltingInstance(null);
            if ($saltObject instanceof \TYPO3\CMS\Saltedpasswords\Salt\SaltInterface) {
                $password = $saltObject->getHashedPassword($password);
            }
        } elseif ($settings['encryptPassword'] === 'md5') {
            $password = md5($password);
        } elseif ($settings['encryptPassword'] === 'sha1') {
            GeneralUtility::deprecationLog(
                'sha1 password encryption is deprecated and will be removed after 2018.02.01'
            );
            $password = sha1($password);
        }

        return $password;
    }

    /**
     * Persist all data that was not stored by now
     *
     * @return void
     */
    protected function persistAll()
    {
        $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)->persistAll();
    }

    /**
     * Redirect to a page with given id
     *
     * @param integer $pageId
     *
     * @return void
     */
    protected function redirectToPage($pageId)
    {
        if ($this->autoLoginTriggered) {
            $statusField = $this->getTypoScriptFrontendController()->fe_user->formfield_permanent;
            $this->uriBuilder->setAddQueryString('&' . $statusField . '=login');
        }

        $url = $this->uriBuilder
            ->setTargetPageUid($pageId)
            ->build();

        $this->redirectToUri($url);
    }


    /**
     * Send emails to user and/or to admin
     *
     * @param FrontendUser $user
     * @param string $type
     *
     * @return FrontendUser
     */
    protected function sendEmails(FrontendUser $user, $type): FrontendUser
    {
        /** @var \Evoweb\SfRegister\Services\Mail $mailService */
        $mailService = $this->objectManager->get(\Evoweb\SfRegister\Services\Mail::class);

        if ($this->isNotifyAdmin($type)) {
            $user = $mailService->sendAdminNotification($user, $type);
        }

        if ($this->isNotifyUser($type)) {
            $user = $mailService->sendUserNotification($user, $type);
        }

        return $user;
    }

    /**
     * Check if the admin need to activate the account
     *
     * @param string $type
     *
     * @return bool
     */
    protected function isNotifyAdmin($type): bool
    {
        $result = false;

        if ($this->settings['notifyAdmin' . $type]) {
            $result = true;
        }

        return $result;
    }

    /**
     * Check if the user need to activate the account
     *
     * @param string $type
     *
     * @return bool
     */
    protected function isNotifyUser($type): bool
    {
        $result = false;

        if ($this->settings['notifyUser' . $type]) {
            $result = true;
        }

        return $result;
    }


    /**
     * Determines whether a user is in a given user group.
     *
     * @param FrontendUser $user
     * @param \Evoweb\SfRegister\Domain\Model\FrontendUserGroup|string|int $userGroup
     *
     * @return bool
     */
    protected function isUserInUserGroup(FrontendUser $user, $userGroup): bool
    {
        $return = false;

        if ($userGroup instanceof \Evoweb\SfRegister\Domain\Model\FrontendUserGroup) {
            $return = $user->getUsergroup()->contains($userGroup);
        } elseif (!empty($userGroup)) {
            $userGroupUids = $this->getEntityUids(
                $user->getUsergroup()->toArray()
            );

            $return = in_array($userGroup, $userGroupUids);
        }

        return $return;
    }

    /**
     * Determines whether a user is in a given user group.
     *
     * @param FrontendUser $user
     * @param array|\Evoweb\SfRegister\Domain\Model\FrontendUserGroup[] $userGroups
     *
     * @return bool
     */
    protected function isUserInUserGroups(FrontendUser $user, array $userGroups): bool
    {
        $return = false;

        foreach ($userGroups as $userGroup) {
            if ($this->isUserInUserGroup($user, $userGroup)) {
                $return = true;
            }
        }

        return $return;
    }

    /**
     * Check if a user has a given usergroup currently set
     *
     * @param int $currentUserGroup
     * @param bool $excludeCurrentUserGroup
     *
     * @return array
     */
    protected function getFollowingUserGroups($currentUserGroup, $excludeCurrentUserGroup = false): array
    {
        $userGroups = $this->getUserGroupIds();
        $currentIndex = array_search((int) $currentUserGroup, $userGroups);
        $additionalIndex = ($excludeCurrentUserGroup ? 1 : 0);

        $reducedUserGroups = [];
        if ($currentUserGroup !== false && $currentUserGroup < count($userGroups)) {
            $reducedUserGroups = array_slice($userGroups, $currentIndex + $additionalIndex);
        }

        return $reducedUserGroups;
    }

    /**
     * Get all configured usergroups
     *
     * @return array
     */
    protected function getUserGroupIds(): array
    {
        $settingsUserGroupKeys = [
            'usergroup',
            'usergroupPostSave',
            'usergroupPostConfirm',
            'usergroupPostAccept',
        ];

        $userGroups = [];
        foreach ($settingsUserGroupKeys as $settingsUserGroupKey) {
            $userGroup = (int) $this->settings[$settingsUserGroupKey];
            if ($userGroup) {
                $userGroups[] = $userGroup;
            }
        }

        return $userGroups;
    }

    /**
     * Gets the uid of each given entity.
     *
     * @param array|\TYPO3\CMS\Extbase\DomainObject\AbstractEntity[] $entities
     *
     * @return array
     */
    protected function getEntityUids(array $entities): array
    {
        $entityUids = [];

        foreach ($entities as $entity) {
            $entityUids[] = $entity->getUid();
        }

        return $entityUids;
    }


    /**
     * Change userGroup of user after activation
     *
     * @param FrontendUser $user
     * @param integer $userGroupIdToAdd
     *
     * @return FrontendUser
     */
    protected function changeUsergroup(
        FrontendUser $user,
        $userGroupIdToAdd
    ): Frontenduser {
        $this->removePreviousUserGroups($user);

        $userGroupIdToAdd = (int) $userGroupIdToAdd;
        if ($userGroupIdToAdd) {
            /** @var \Evoweb\SfRegister\Domain\Model\FrontendUserGroup $userGroupToAdd */
            $userGroupToAdd = $this->userGroupRepository->findByUid($userGroupIdToAdd);
            $user->addUsergroup($userGroupToAdd);
        }

        return $user;
    }

    /**
     * Removes all frontend usergroups that were set in previous actions
     *
     * @param FrontendUser $user
     *
     * @return void
     */
    protected function removePreviousUserGroups(FrontendUser $user)
    {
        $userGroupIds = $this->getUserGroupIds();
        $assignedUserGroups = $user->getUsergroup();
        foreach ($assignedUserGroups as $singleUserGroup) {
            if (\in_array($singleUserGroup->getUid(), $userGroupIds)) {
                $assignedUserGroups->detach($singleUserGroup);
            }
        }
        $user->setUsergroup($assignedUserGroups);
    }


    /**
     * Login user with service
     *
     * @param FrontendUser $user
     * @param int $redirectPageId
     *
     * @return void
     */
    protected function autoLogin(FrontendUser $user, &$redirectPageId)
    {
        session_start();
        $this->autoLoginTriggered = true;

        $_SESSION['sf-register-user'] = GeneralUtility::hmac('auto-login::' . $user->getUid(), $GLOBALS['EXEC_TIME']);

        /** @var \TYPO3\CMS\Core\Registry $registry */
        $registry = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Registry::class);
        $registry->set('sf-register', $_SESSION['sf-register-user'], $user->getUid());

        // if redirect was empty by now set it to current page
        if (intval($redirectPageId) == 0) {
            $redirectPageId = $this->getTypoScriptFrontendController()->id;
        }

        // get configured redirect page id if given
        $userGroups = $user->getUsergroup();
        /** @var \Evoweb\SfRegister\Domain\Model\FrontendUserGroup $userGroup */
        foreach ($userGroups as $userGroup) {
            if ($userGroup->getFeloginRedirectPid()) {
                $redirectPageId = $userGroup->getFeloginRedirectPid();
                break;
            }
        }

        if ($redirectPageId > 0) {
            $this->redirectToPage($redirectPageId);
        }
    }

    /**
     * Checks if an user is logged in
     *
     * @return bool
     */
    protected function userIsLoggedIn()
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        return is_array($this->getTypoScriptFrontendController()->fe_user->user);
    }

    /**
     * Determines the frontend user, either if it's
     * already submitted, or by looking up the mail hash code.
     *
     * @param NULL|FrontendUser $user
     * @param NULL|string $hash
     *
     * @return NULL|FrontendUser
     */
    protected function determineFrontendUser(FrontendUser $user = null, $hash = null)
    {
        $frontendUser = null;

        $requestArguments = $this->request->getArguments();
        if ($user !== null && $hash !== null) {
            $calculatedHash = GeneralUtility::hmac(
                $requestArguments['action'] . '::' . $user->getUid()
            );
            if ($hash === $calculatedHash) {
                $frontendUser = $user;
            }
        }

        return $frontendUser;
    }


    /**
     * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }
}
