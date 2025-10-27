<?php
/**
 * Isolate plugin for Craft CMS 3.x
 *
 * Force users to only access a subset of your entries
 *
 * @link      https://trendyminds.com
 * @copyright Copyright (c) 2019 TrendyMinds
 */

namespace trendyminds\isolate\services;

use craft\models\Section;
use craft\services\Sections;
use craft\services\Structures;
use trendyminds\isolate\records\IsolateAssetRecord;
use trendyminds\isolate\records\IsolateRecord;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\elements\User;
use craft\elements\Entry;
use craft\db\Query;
use yii\web\ForbiddenHttpException;

/**
 * @author    TrendyMinds
 * @package   Isolate
 * @since     1.0.0
 */
class IsolateService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Get users
     *
     * Fetches all users who can be isolated.
     * This also notes which users are "isolated"
     * Does not include admins or users who can't access the control panel
     *
     * @return array
     */
    public function getUsers(int $groupId = null)
    {
        $data = [];
        $isolatedUsers = [];

        $users = User::find()
            ->admin(false)
            ->can("accessCp")
            ->groupId($groupId)
            ->all();

        $isolateEntries = IsolateRecord::find()->all();

        foreach ($isolateEntries as $entry) {
            $isolatedUsers[] = $entry->userId;
        }

        $isolatedUsers = array_values(array_unique($isolatedUsers));
        $fallbackUserImage = Craft::$app->getAssetManager()->getPublishedUrl('@app/web/assets/cp/dist', true, 'images/user.svg');

        foreach ($users as $user) {
            $data[] = [
                "id" => $user->id,
                "name" => $user->name,
                "fullName" => $user->fullName,
                "email" => $user->email,
                "dateCreated" => $user->dateCreated,
                "photo" => isset($user->photo) ? "/index.php?p=admin/actions/assets/thumb&uid={$user->photo->uid}&width=30&height=30" : $fallbackUserImage,
                "isIsolated" => in_array($user->id, $isolatedUsers),
            ];
        }

        return $data;
    }

    /**
     * Gets the sections a user has access to
     *
     * This also notes what sections are "isolated"
     *
     * @param integer $userId
     * @return array
     */
    public function getUserSections(int $userId)
    {
        $data = [];
        $isolatedSections = [];

        $sections = Craft::$app->sections->getAllSections();

        $isolateEntries = IsolateRecord::findAll([
            "userId" => $userId
        ]);

        foreach ($isolateEntries as $entry) {
            $isolatedSections[] = $entry->sectionId;
        }

        $isolatedSections = array_values(array_unique($isolatedSections));

        foreach ($sections as $section) {
            $data[] = [
                "id" => $section->id,
                "name" => $section->name,
                "handle" => $section->handle,
                "isIsolated" => in_array($section->id, $isolatedSections),
            ];
        }

        return $data;
    }

	/**
	 * Get all entries by section
	 *
	 * @param int      $sectionId
	 * @param int|bool $siteId
	 *
	 * @return array
	 */
    public function getAllEntries(int $sectionId, int|bool $siteId = false)
    {
        return $this->groupEntries(
            Entry::findAll([ "sectionId" => $sectionId, "status" => null, 'siteId' => $siteId ])
        );
    }

    /**
     * Returns if the given section is a structure
     *
     * @param int $sectionId
     * @return bool
     */
    public function isStructure(int $sectionId)
    {
        /** @var Sections $sections */
        $sections = Craft::$app->getSections();
        $section = $sections->getSectionById($sectionId);

        return $section->type === Section::TYPE_STRUCTURE;
    }

    /**
     * Groups entries with the same ID (multi-site setup)
     *
     * @param array $entries
     * @return array
     */
    protected function groupEntries(array $entries): array
    {
        $map = [];

        foreach ($entries as $entry) {
            if (!array_key_exists($entry['id'], $map)) {
                $map[$entry['id']] = $entry;
            } else {
                $map[$entry['id']]['title'] .= ' | ' . $entry['title'];
            }
        }

        return array_values($map);
    }

    /**
     * Returns the isolated entries for a given user
     *
     * @param integer $userId
     * @param integer $sectionId
     * @return IsolateRecord[]
     */
    public function getIsolatedEntries(int $userId, int $sectionId = null)
    {
        return IsolateRecord::findAll([
            "userId" => $userId,
            "sectionId" => $sectionId
        ]);
    }

    /**
     * Modifies database record of an isolated user (adds/edit/removes)
     *
     * @param integer $userId
     * @param integer $sectionId
     * @param array $entries
     * @return void
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function modifyRecords(int $userId, int $sectionId, array $entries)
    {
        /**
         * Remove entries that were de-selected
         */
        $existingEntries = IsolateRecord::findAll([
            "userId" => $userId,
            "sectionId" => $sectionId
        ]);

        $existingEntries = array_map(function($permission) {
            return $permission->entryId;
        }, $existingEntries);

        $entriesToRemove = array_values(array_diff($existingEntries, $entries));

        foreach ($entriesToRemove as $entryId)
        {
            $record = IsolateRecord::findOne([
                "entryId" => $entryId
            ]);

            $record->delete();
        }

        /**
         * Add entries that are new selections
         */
        $entriesToAdd = array_values(array_diff($entries, $existingEntries));

        foreach ($entriesToAdd as $entryId)
        {
            $record = new IsolateRecord;
            $record->setAttribute('userId', $userId);
            $record->setAttribute('sectionId', $sectionId);
            $record->setAttribute('entryId', $entryId);
            $record->save();
        }

        // Get the total number of entries a user now has access to
        $totalEntries = (int) IsolateRecord::find(["userId" => $userId])->count();

        /**
         * If a user has been assigned permissions, enable Isolate automatically to make the workflow contained in one place
         */
        if ($totalEntries > 0) {
            $usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);
            $usersPermissions[] = "accessplugin-isolate";
            Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
        }

        /**
         * If a user has no assigned permissions disable their access to Isolate
         */
        if ($totalEntries === 0) {
            $usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);
            $usersPermissions = array_filter($usersPermissions, function($permission) {
                return $permission !== "accessplugin-isolate";
            });
            Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
        }
    }

    /**
     * Is the user isolated?
     *
     * @param integer $userId
     * @return boolean
     */
    public function isUserIsolated(int $userId)
    {
        // If this user is assigned an entry then this user is isolated
        $userHasIsolateRecord = IsolateRecord::findOne([
            "userId" => $userId
        ]);

        if ($userHasIsolateRecord) {
            return true;
        }

        return false;
    }
	
	/**
	 * Checks if a user can edit an asset give a path
	 *
	 * @param integer $userId
	 * @param string $path
	 * @param bool $redirect_to_dashboard
	 * @return bool
	 * @throws ForbiddenHttpException
	 */
	public function verifyIsolatedUserAssetAccess(int $userId, string $path, bool $redirect_to_dashboard = true)
	{
		$segments = Craft::$app->request->getSegments();
		
		// If a user is attempting to edit a specific entry (but not create a new one)
		if (count($segments) && $segments[0] === "assets" && isset($segments[2]) && (!Craft::$app->request->getParam('fresh') && $segments[2] !== "new"))
		{
			// Get the ID of the entry a user is accessing
			preg_match("/^\d*/", $segments[2], $matches);
			
			$asset_id = intval($matches[0]);
			if(!($asset_id > 0)) {
				return true;
			}
			
			// Compare the ID to the list of IDs a user *can* access
			$accessibleIds = $this->getUserAssetIds($userId);
			$canAccess = in_array($asset_id, $accessibleIds);
			
			if (!$canAccess)
			{
				throw new ForbiddenHttpException('User is not permitted to perform this action');
			}
		}
		
		// Deny isolated user access to the entries area by redirecting back to dashboard
		// Redirecting because saving an entry often takes a user back to the entries listing
		if (count($segments) && $segments[0] === "assets" && !(isset($segments[2])) && $redirect_to_dashboard)
		{
			$url = "isolate";
			
			if (isset($segments[1])) {
				$url = "isolate/dashboard/$segments[1]";
			}
			
			header('Location: ' . UrlHelper::cpUrl($url));
			
			return;
		}
		
		return true;
	}
	
	/**
	 * Is the user isolated?
	 *
	 * @param integer $userId
	 * @return boolean
	 */
	public function isUserAssetIsolated(int $userId)
	{
		// If this user is assigned an entry then this user is isolated
		$userHasIsolateRecord = IsolateAssetRecord::findOne([
			"userId" => $userId
		]);
		
		if ($userHasIsolateRecord) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Gets the IDs of every entry a user can edit
	 * Can be scoped down to specific sections
	 *
	 * @param integer $userId
	 * @param int|null $sectionId
	 * @param int|null $siteId
	 * @return array
	 */
    public function getUserEntriesIds(int $userId, int $sectionId = null, int $siteId = null)
    {
        $isoQuery = new Query();

        // Get all of the records that a user has been explicitly isolated into
        $isolatedRecords = $isoQuery->select(["iso.*"])
            ->from("{{%isolate_permissions}} iso")
            ->where(["iso.userId" => $userId])
            ->andFilterWhere(["iso.sectionId" => $sectionId])
            ->all();

        $isolatedEntryIds = [];

        // If the user is isolated we need to get the sections they are isolated into and the IDs of the entries they are isolated into
        if (count($isolatedRecords) > 0)
        {

            $isolatedEntryIds = array_map(function($record) {
                return $record['entryId'];
            }, $isolatedRecords);

        }
		
        return $isolatedEntryIds;
    }
	
	/**
	 * Takes the IDs of every entry a user can access and returns an entry model loop
	 *
	 * @param integer $userId
	 * @param int|null $sectionId
	 * @param int $limit
	 * @param bool $getDrafts
	 * @param int|null $siteId
	 * @return array
	 */
    public function getUserEntries(int $userId, int $sectionId = null, int $limit = 50, bool $getDrafts = false, int $siteId = null)
    {
        $ids = $this->getUserEntriesIds($userId, $sectionId, $siteId);
        return Entry::find()->id($ids)->status(null)->limit($limit)->drafts($getDrafts)->siteId($siteId);
    }
	
	/**
	 * Checks if a user can edit an entry give a path
	 *
	 * @param integer $userId
	 * @param string $path
	 * @param bool $redirect_to_dashboard
	 * @return bool
	 * @throws ForbiddenHttpException
	 */
    public function verifyIsolatedUserAccess(int $userId, string $path, bool $redirect_to_dashboard = true)
    {
        $segments = Craft::$app->request->getSegments();

        // If a user is attempting to edit a specific entry (but not create a new one)
        if (count($segments) && $segments[0] === "entries" && isset($segments[2]) && (!Craft::$app->request->getParam('fresh') && $segments[2] !== "new"))
        {
            // Get the ID of the entry a user is accessing
            preg_match("/^\d*/", $segments[2], $matches);

            // Compare the ID to the list of IDs a user *can* access
            $accessibleIds = $this->getUserEntriesIds($userId);
            $canAccess = in_array($matches[0], $accessibleIds);

            if (!$canAccess)
            {
                throw new ForbiddenHttpException('User is not permitted to perform this action');
            }
        }

        // Deny isolated user access to the entries area by redirecting back to dashboard
        // Redirecting because saving an entry often takes a user back to the entries listing
        if (count($segments) && $segments[0] === "entries" && !(isset($segments[2])) && $redirect_to_dashboard)
        {
            $url = "isolate";

            if (isset($segments[1])) {
                $url = "isolate/dashboard/$segments[1]";
            }

            header('Location: ' . UrlHelper::cpUrl($url));

            return;
        }

        return true;
    }
	
	/**
	 * Modifies database record of an isolated user (adds/edit/removes)
	 *
	 * @param integer $userId
	 * @param int[] $entries
	 * @return void
	 * @throws \Throwable
	 * @throws \yii\db\StaleObjectException
	 */
	public function modifyAssetRecords(int $userId, array $assetIds)
	{
		/**
		 * Remove entries that were de-selected
		 */
		$existingEntries = IsolateAssetRecord::findAll([
			"userId" => $userId,
		]);
		
		$existingEntries = array_map(function($permission) {
			return $permission->assetsId;
		}, $existingEntries);
		
		$entriesToRemove = array_values(array_diff($existingEntries, $assetIds));
		
		foreach ($entriesToRemove as $entryId)
		{
			$record = IsolateAssetRecord::findOne([
				"assetsId" => $entryId
			]);
			
			$record->delete();
		}
		
		/**
		 * Add entries that are new selections
		 */
		$entriesToAdd = array_values(array_diff($assetIds, $existingEntries));
		
		foreach ($entriesToAdd as $entryId)
		{
			$record = new IsolateAssetRecord();
			$record->setAttribute('userId', $userId);
			$record->setAttribute('assetsId', $entryId);
			$record->save();
		}
		
		// Get the total number of entries a user now has access to
		$totalEntries = (int) IsolateAssetRecord::find(["userId" => $userId])->count();
		
		/**
		 * If a user has been assigned permissions, enable Isolate automatically to make the workflow contained in one place
		 */
		if ($totalEntries > 0) {
			$usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);
			$usersPermissions[] = "accessplugin-isolate-assets";
			Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
		}
		
		/**
		 * If a user has no assigned permissions disable their access to Isolate
		 */
		if ($totalEntries === 0) {
			$usersPermissions = Craft::$app->userPermissions->getPermissionsByUserId($userId);
			$usersPermissions = array_filter($usersPermissions, function($permission) {
				return $permission !== "accessplugin-isolate-assets";
			});
			Craft::$app->userPermissions->saveUserPermissions($userId, $usersPermissions);
		}
	}
	
	/**
	 * Gets the IDs of every entry a user can edit
	 * Can be scoped down to specific sections
	 *
	 * @param integer $userId
	 * @return array
	 */
	public function getUserAssetIds(int $userId)
	{
		$isoQuery = new Query();
		
		// Get all of the records that a user has been explicitly isolated into
		$isolatedRecords = $isoQuery->select(["iso.*"])
		                            ->from("{{%isolate_permissions_assets}} iso")
		                            ->where(["iso.userId" => $userId])
		                            ->all();
		
		$isolatedEntryIds = [];
		
		if (count($isolatedRecords) > 0)
		{
			
			$isolatedEntryIds = array_map(function($record) {
				return $record['assetsId'];
			}, $isolatedRecords);
			
		}
		
		return $isolatedEntryIds;
	}
}
