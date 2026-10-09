<?php

namespace App\Http\Controllers;

use App\Models\TechnicianReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TechnicianReviewController extends Controller
{
    /**
     * Store or update a dual-factor technician review.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:users,id',
            'rating_stars'  => 'nullable|numeric|min:1|max:5',
            'rating_time'   => 'required|numeric|min:1|max:5',
            'comment'       => 'nullable|string|max:1000',
            'work_order_id' => 'nullable|integer',
            'service_id'    => 'nullable|integer',
        ]);

        $clientId = $request->user() ? $request->user()->id : ($request->input('client_id') ?? 1);
        $technicianId = (int) $validated['technician_id'];

        $scheduledAt = null;
        $arrivedAt = null;
        $delayMinutes = null;

        if (!empty($validated['work_order_id'])) {
            $wo = WorkOrder::withoutGlobalScopes()->find($validated['work_order_id']);
            if ($wo) {
                $scheduledAt = $wo->scheduled_at;
                $arrivedAt = $wo->arrived_at;
            }
        } elseif (!empty($validated['service_id'])) {
            $serv = Service::withoutGlobalScopes()->find($validated['service_id']);
            if ($serv) {
                $scheduledAt = $serv->scheduled_at ?? $serv->created_at;
                $arrivedAt = $serv->arrived_at;
            }
        }

        if ($scheduledAt && $arrivedAt) {
            try {
                $schedTime = Carbon::parse($scheduledAt);
                $arrTime = Carbon::parse($arrivedAt);
                $delayMinutes = (int) $schedTime->diffInMinutes($arrTime, false);
            } catch (\Exception $e) {
                $delayMinutes = null;
            }
        }

        // Check if existing review for this work order or service by this client
        $reviewQuery = TechnicianReview::where('client_id', $clientId)
            ->where('technician_id', $technicianId);

        if (!empty($validated['work_order_id'])) {
            $reviewQuery->where('work_order_id', $validated['work_order_id']);
        } elseif (!empty($validated['service_id'])) {
            $reviewQuery->where('service_id', $validated['service_id']);
        }

        $review = $reviewQuery->first();

        if ($review) {
            $review->update([
                'rating_stars'  => $validated['rating_stars'] ?? $review->rating_stars,
                'rating_time'   => $validated['rating_time'],
                'comment'       => $validated['comment'] ?? $review->comment,
                'scheduled_at'  => $scheduledAt ?? $review->scheduled_at,
                'arrived_at'    => $arrivedAt ?? $review->arrived_at,
                'delay_minutes' => $delayMinutes ?? $review->delay_minutes,
            ]);
        } else {
            $review = TechnicianReview::create([
                'technician_id' => $technicianId,
                'client_id'     => $clientId,
                'work_order_id' => $validated['work_order_id'] ?? null,
                'service_id'    => $validated['service_id'] ?? null,
                'rating_stars'  => $validated['rating_stars'] ?? null,
                'rating_time'   => $validated['rating_time'],
                'comment'       => $validated['comment'] ?? null,
                'scheduled_at'  => $scheduledAt,
                'arrived_at'    => $arrivedAt,
                'delay_minutes' => $delayMinutes,
            ]);
        }

        if (!empty($validated['work_order_id'])) {
            \Illuminate\Support\Facades\DB::table('technician_cancellations')
                ->where('work_order_id', $validated['work_order_id'])
                ->where('technician_id', $technicianId)
                ->update(['rated' => true]);
        }

        // Recalculate technician rating stats
        $technician = User::withoutGlobalScopes()->find($technicianId);
        if ($technician) {
            $technician->recalculateRatings();
        }

        return response()->json([
            'status' => 'success',
            'message' => '¡Calificación registrada exitosamente!',
            'review' => $review,
            'technician_stats' => [
                'rating_stars_avg'    => $technician ? $technician->rating_stars_avg : 5.00,
                'rating_time_avg'     => $technician ? $technician->rating_time_avg : 5.00,
                'total_reviews_count' => $technician ? $technician->total_reviews_count : 1,
            ]
        ], 201);
    }

    /**
     * Get reviews and metrics for a specific technician.
     */
    public function getTechnicianReviews($technicianId)
    {
        $technician = User::withoutGlobalScopes()->findOrFail($technicianId);

        $reviews = TechnicianReview::with(['client:id,first_name,last_name,profile_picture'])
            ->where('technician_id', $technicianId)
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get()
            ->map(function ($rev) {
                return [
                    'id'            => $rev->id,
                    'rating_stars'  => (float) $rev->rating_stars,
                    'rating_time'   => (float) $rev->rating_time,
                    'comment'       => $rev->comment,
                    'delay_minutes' => $rev->delay_minutes,
                    'created_at'    => $rev->created_at ? $rev->created_at->toIso8601String() : null,
                    'client_name'   => $rev->client ? $rev->client->name : 'Cliente Anónimo',
                    'client_picture'=> $rev->client ? $rev->client->profile_picture : null,
                ];
            });

        return response()->json([
            'technician' => [
                'id'                  => $technician->id,
                'name'                => $technician->name,
                'profile_picture'     => $technician->profile_picture,
                'rating_stars_avg'    => (float) ($technician->rating_stars_avg ?? 5.00),
                'rating_time_avg'     => (float) ($technician->rating_time_avg ?? 5.00),
                'total_reviews_count' => (int) ($technician->total_reviews_count ?? count($reviews)),
            ],
            'reviews' => $reviews
        ]);
    }

    /**
     * Check if a client has already reviewed a work order or service.
     */
    public function checkReviewStatus(Request $request)
    {
        $clientId = $request->user() ? $request->user()->id : $request->input('client_id');
        $workOrderId = $request->input('work_order_id');
        $serviceId = $request->input('service_id');

        $query = TechnicianReview::query();
        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($workOrderId) {
            $query->where('work_order_id', $workOrderId);
        } elseif ($serviceId) {
            $query->where('service_id', $serviceId);
        } else {
            return response()->json(['has_reviewed' => false]);
        }

        $review = $query->first();

        return response()->json([
            'has_reviewed' => !is_null($review),
            'review' => $review
        ]);
    }
}