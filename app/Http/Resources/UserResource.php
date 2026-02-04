<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language' => $this->preferred_language,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'is_super_admin' => $this->is_super_admin,
            'two_factor_enabled' => $this->two_factor_enabled,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),

            'organization' => $this->when(
                $this->relationLoaded('organization'),
                fn () => new OrganizationResource($this->organization)
            ),

            'branches' => $this->when(
                $this->relationLoaded('branches'),
                fn () => BranchResource::collection($this->branches)
            ),

            'roles' => $this->when(
                $this->relationLoaded('roles'),
                fn () => $this->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ])
            ),

            'default_branch' => $this->when(
                $this->relationLoaded('branches'),
                fn () => $this->branches->firstWhere('pivot.is_default', true)?->only(['id', 'uuid', 'name', 'code'])
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
