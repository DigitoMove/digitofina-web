<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportExportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_type' => $this->report_type,
            'format' => $this->format,
            'filters' => $this->filters,
            'status' => $this->status,
            'download_url' => $this->status === 'completed' && $this->path
                ? \URL::temporarySignedRoute('api.v1.reports.exports.download', now()->addMinutes(10), ['reportExport' => $this->id])
                : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
