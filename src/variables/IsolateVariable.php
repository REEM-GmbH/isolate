<?php
/**
 * Isolate plugin for Craft CMS 3.x
 *
 * Force users to only access a subset of your entries
 *
 * @link      https://trendyminds.com
 * @copyright Copyright (c) 2019 TrendyMinds
 */

namespace trendyminds\isolate\variables;

use trendyminds\isolate\Isolate;

use Craft;

/**
 * @author    TrendyMinds
 * @package   Isolate
 * @since     1.0.0
 */
class IsolateVariable
{
    // Public Methods
    // =========================================================================
    public function users()
    {
        return Isolate::$plugin->isolateService->getUsers();
    }

    public function getUserEntries(int $userId, int $sectionId = null)
    {
        return Isolate::$plugin->isolateService->getUserEntries($userId, $sectionId);
    }

    public function nav()
    {
        return Isolate::$plugin->isolateService->getEntriesNav();
    }
}
