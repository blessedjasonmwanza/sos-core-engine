<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegistrationController;
use App\Http\Controllers\API\SignatureController;
use App\Http\Controllers\API\StaffController;
use App\Http\Controllers\API\UserController;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Events\EmergencyAlertEvent;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::resource('signup', RegistrationController::class)->only(['index', 'store', 'update', 'destroy']);
Route::resource('createMedicalStaff', StaffController::class)->only(['index', 'store', 'update', 'destroy']);
Route::resource('signature', SignatureController::class)->only(['index', 'store', 'update', 'destroy']);
Route::post('/refresh', [RegistrationController::class, 'refreshToken'])
    ->middleware('auth:sanctum');
Route::post('/verifyOtp', [RegistrationController::class, 'verifyOtp'])
    ->middleware('auth:sanctum');
Route::get('/me', [UserController::class, 'me'])
    ->middleware('auth:sanctum');
Route::post('/update-location', [StaffController::class, 'updateLocation'])
    ->middleware('auth:sanctum');
Route::get('/active-staffs', [StaffController::class, 'listActiveStaffs']);

Route::get('/emergency_statuses/{id}', [StaffController::class, 'listEmergencyStatuses'])
    ->middleware('auth:sanctum');
    Route::post('/incident-reports', [StaffController::class, 'submitIncidentReport'])
    ->middleware('auth:sanctum');

Route::post('/onboard', [UserController::class, 'onboard']);


Route::post('/update-fcm-token', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'fcm_token' => 'required|string',
        'email' => 'required|string|email|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
        ], 422);
    }

    $validated = $validator->validated();

    $user = User::where('email', $request->email)->first();
    if (!$user || !$user->staff) {
        return response()->json(['message' => 'Staff not found'], 404);
    }
    
    $user->staff->update([
        'fcm_token' => $validated['fcm_token'],
    ]);

    return response()->json([
        'success' => true,
        'message' => 'FCM token updated successfully',
        'token' => $validated['fcm_token']
    ]);
})->middleware('auth:sanctum');


Route::post('/forgot-password', function (Request $request) {

    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
        ], 422);
    }


    $user = User::where('email', $request->email)->first();
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'We could not find a user with that email address.',
        ], 404);
    }


    $status = Password::sendResetLink($request->only('email'));

    if ($status === Password::RESET_LINK_SENT) {
        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to your email.',
        ]);
    }


    return response()->json([
        'success' => false,
        'message' => 'Unable to send reset link. Please try again later.',
    ], 400);
});


Route::post('/staff-login', function (Request $request) {
    try {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $validated = $validator->validated();

        $user = User::where('email', $validated['email'])->first();
        
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->staff) {
            return response()->json([
                'success' => false,
                'message' => 'Account exists, but no associated staff record found.'
            ], 403);
        }

        if ((int)$user->staff->is_approved != 1) {
            $status = $user->staff->is_approved == 2 ? 'pending approval' : 'not approved';
            return response()->json([
                'success' => false,
                'message' => 'Your staff account is currently ' . $status . '.'
            ], 403);
        }

        $token = $user->createToken('staff-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user
        ]);
    } catch (\Throwable $th) {
        Log::error($th->getMessage());
        return response()->json([
            'message' => 'Something went wrong',
            'error' => $th->getMessage()
        ], 401);
    }
});


// routes/api.php
Route::post('/emergency-help', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'latitude' => 'required|numeric',
        'longitude' => 'required|numeric',
        'phone' => 'required|string',
        'timestamp' => 'required|date',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
        ], 422);
    }

    $validated = $validator->validated();

    // Get victim's location
    $victimLat = $validated['latitude'];
    $victimLon = $validated['longitude'];
    $victimPhone = $validated['phone'];

    // Get all active staff with location data
    $staffMembers = \App\Models\Staff::whereNotNull('last_known_latitude')
        ->whereNotNull('last_known_longitude')
        ->get();

    // Calculate distances and find closest staff
    $staffWithDistances = $staffMembers->map(function ($staff) use ($victimLat, $victimLon) {
        $distance = calculateDistance(
            $victimLat, 
            $victimLon, 
            $staff->last_known_latitude, 
            $staff->last_known_longitude
        );
        
        return [
            'staff' => $staff,
            'distance_km' => $distance
        ];
    });

    // Sort by closest distance
    $sortedStaff = $staffWithDistances->sortBy('distance_km');

    // Get the closest staff member
    $closestStaff = $sortedStaff->first();

    if (!$closestStaff) {
        return response()->json([
            'message' => 'No active heath practitioners found with known location.',
        ], 404);
    }

    // Log the emergency incident
    $emergency = \App\Models\EmergencyHelp::create([
        'phone' => $victimPhone,
        'latitude' => $victimLat,
        'longitude' => $victimLon,
        'attended_by' => $closestStaff['staff']->id,
        'closest_staff_distance' => $closestStaff['distance_km'],
        'active' => 1
    ]);

    // ✅ Trigger the Pusher event with unique vibration
    broadcast(new EmergencyAlertEvent($closestStaff['staff'], $emergency, $closestStaff['distance_km']));

    // Also send SMS as backup
    //sendEmergencySMS($closestStaff['staff'], $emergency, $closestStaff['distance_km']);

    return response()->json([
        'message' => 'Help request received and closest staff notified via real-time alert',
        'closest_staff' => [
            'name' => $closestStaff['staff']->user->name,
            'phone' => $closestStaff['staff']->user->phone_number,
            'distance_km' => round($closestStaff['distance_km'], 2),
        ],
        'emergency_id' => $emergency->id,
    ], 200);
});



// Haversine formula to calculate distance in km
function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

// Send SMS to staff member
function sendEmergencySMS($staff, $emergency, $distance)
{
    $message = "🚨 EMERGENCY ALERT 🚨\n" .
        "A person needs immediate assistance!\n" .
        "Victim Phone: {$emergency->victim_phone}\n" .
        "Your distance: " . round($distance, 2) . " km\n" .
        "Location: https://maps.google.com/?q={$emergency->victim_latitude},{$emergency->victim_longitude}\n" .
        "Time: " . now()->format('Y-m-d H:i:s') . "\n" .
        "Please respond immediately!";


    try {
        Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode(env('TWILIO_SID') . ':' . env('TWILIO_TOKEN'))
        ])->post('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_SID') . '/Messages.json', [
            'From' => env('TWILIO_PHONE_NUMBER'),
            'To' => $staff->user->phone_number,
            'Body' => $message
        ]);

        Log::info("Emergency SMS sent to staff: {$staff->user->name} at {$staff->user->phone_number}");
    } catch (\Exception $e) {
        Log::error("Failed to send SMS: " . $e->getMessage());
    }



}

            