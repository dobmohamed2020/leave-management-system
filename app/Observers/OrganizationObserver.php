<?php

namespace App\Observers;

use App\Models\Organization;

/**
 * Handles two concerns:
 *  1. Auto-builds / updates the materialized `path` when creating or moving a node.
 *  2. Cascades path updates to all descendants when a node moves.
 */
class OrganizationObserver
{
    /**
     * Before saving, recompute path and depth so they're always consistent.
     * This fires on both create and update.
     */
    public function saving(Organization $organization): void
    {
        if ($organization->parent_id === null) {
            $organization->depth = 0;
            if ($organization->exists) {
                $organization->path = "/{$organization->id}/";
            } elseif (empty($organization->path)) {
                // Temporary path satisfying NOT NULL during initial INSERT
                $organization->path = '/';
            }
            return;
        }

        $parent = Organization::withoutGlobalScopes()
            ->select('id', 'path', 'depth')
            ->find($organization->parent_id);

        if ($parent) {
            $organization->depth = (int) $parent->depth + 1;
            $parentPath = rtrim($parent->path ?? '/', '/') . '/';

            if ($organization->exists) {
                $organization->path = $parentPath . "{$organization->id}/";
            } elseif (empty($organization->path)) {
                // Temporary path satisfying NOT NULL during initial INSERT
                $organization->path = $parentPath;
            }
        } else {
            $organization->depth = 0;
            if (! $organization->exists && empty($organization->path)) {
                $organization->path = '/';
            }
        }
    }

    /**
     * After a new node is created we always know the ID,
     * so we can finalize the path.
     */
    public function created(Organization $organization): void
    {
        $finalPath = "/{$organization->id}/";

        if ($organization->parent_id !== null) {
            $parent = Organization::withoutGlobalScopes()
                ->select('path')
                ->find($organization->parent_id);

            if ($parent && ! empty($parent->path)) {
                $finalPath = rtrim($parent->path, '/') . "/{$organization->id}/";
            }
        }

        $organization->updateQuietly(['path' => $finalPath]);
        $organization->path = $finalPath;
    }

    /**
     * If parent_id changed, cascade the new path to all descendants.
     * Uses a single SQL UPDATE with REPLACE for efficiency.
     */
    public function updated(Organization $organization): void
    {
        if (! $organization->wasChanged('parent_id')) {
            return;
        }

        $this->cascadePathToDescendants($organization);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function cascadePathToDescendants(Organization $organization): void
    {
        // Fetch fresh path after the update
        $newPath = $organization->fresh()?->path ?? $organization->path;
        $oldPath = $organization->getOriginal('path');

        if ($oldPath === $newPath || empty($oldPath)) {
            return;
        }

        // Get all descendants (excluding self — already updated)
        $descendants = Organization::withoutGlobalScopes()
            ->where('path', 'like', $oldPath . '%')
            ->where('id', '!=', $organization->id)
            ->get(['id', 'path', 'depth']);

        foreach ($descendants as $descendant) {
            $updatedPath  = $newPath . ltrim(substr($descendant->path, strlen($oldPath)), '/');
            $updatedDepth = substr_count(trim($updatedPath, '/'), '/');

            $descendant->updateQuietly([
                'path'  => $updatedPath,
                'depth' => $updatedDepth,
            ]);
        }
    }
}
