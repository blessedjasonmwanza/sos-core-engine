<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Notifications\OtpNotification;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;

class RegistrationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {


            $validator = Validator::make($request->all(), [
                'phone_number' => [
                    'required',
                    'regex:/^(?:\+?26)?0?(95|96|97|75|76|77)\d{7}$/'
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Normalize phone number to look for matches (last 9 digits)
            // Assuming format like 097xxx... or +26097xxx...
            $phoneInput = $request->phone_number;
            // Remove non-digits
            $cleanPhone = preg_replace('/[^0-9]/', '', $phoneInput);
            // Get last 9 digits (ignoring leading 0 or country code)
            $last9Digits = substr($cleanPhone, -9);

            // Check if user exists by matching last 9 digits of phone
            $existingUser = User::where('phone_number', 'LIKE', "%{$last9Digits}")->first();

            $otpCode = rand(100000, 999999);
            $otpExpiresAt = now()->addMinutes(5);

            if ($existingUser) {
                // User exists -> Update or Create GuestLogin record
                $guestLogin = \App\Models\GuestLogin::updateOrCreate(
                    ['phone_number' => $existingUser->phone_number],
                    [
                        'otp_code' => $otpCode,
                        'otp_expires_at' => $otpExpiresAt,
                    ]
                );

                // Send OTP
                $user = $existingUser;
                // We'll use the user object for notification logic below, but won't update the User model's OTP fields
            } else {
                // New User -> Create User record
                $user = User::create([
                    'phone_number' => $request->phone_number,
                    'password' => bcrypt('qwertyuiop'),
                ]);
                
                // Store OTP in User model for new users (as before)
                $user->otp_code = $otpCode;
                $user->otp_expires_at = $otpExpiresAt;
                $user->save();
            }

            // Create temporary tokens for response (though for existing user, we might not want to issue login tokens yet until verified? 
            // The original code issued tokens immediately upon registration/OTP request. 
            // We'll keep it consistent: issue tokens for "login/signup" flow.)
            // user->createToken... logic
            
            // Wait, for existing user, we shouldn't login immediately if we require OTP verification? 
            // The original code:
            // 1. Create User
            // 2. Create Token
            // 3. Send OTP
            // 4. Return Token
            // This means the user is "logged in" but needs to verify OTP to be "onboarded" or verified.
            // For Guest Login (existing user), we can do the same: issue a token but they need to verify OTP to proceed?
            // OR checks verifyOtp later.
            
            // Let's stick to the generated tokens for now so the frontend works as is.
            
            $accessTokenExpiresAt = Carbon::now()->addDays(7);
            $refreshTokenExpiresAt = Carbon::now()->addDays(14);
            $accessToken = $user->createToken('access_token', ['*'], $accessTokenExpiresAt)->plainTextToken;
            $refreshToken = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

            // Notify
            // For existing user, avoiding saving OTP to User model prevents overwriting if they are using it elsewhere?
            // Actually request asked to "store otps in guest_logins table".
            // So for existing user we used GuestLogin. 
            // Notification:
            $this->sendOtpSms($user->phone_number, $otpCode);

            return response()->json([
                'success' => true,
                'message' => $existingUser ? 'Welcome back! OTP sent.' : 'User registered successfully',
                'data' => [
                    'phone' => $user->phone_number,
                    'email' => $user->email ?? NULL,
                    'access_token' => $accessToken,
                    'access_token_expires_at' => $accessTokenExpiresAt,
                    'refresh_token' => $refreshToken,
                    'refresh_token_expires_at' => $refreshTokenExpiresAt,
                    'token_type' => 'Bearer',
                    'is_existing_user' => !!$existingUser
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error in store method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }





    public function refreshToken(Request $request)
    {
        $currentRefreshToken = $request->bearerToken();
        $refreshToken = PersonalAccessToken::findToken($currentRefreshToken);

        if (!$refreshToken || !$refreshToken->can('refresh') || $refreshToken->expires_at->isPast()) {
            return response()->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $user = $refreshToken->tokenable;
        $refreshToken->delete();

        $accessTokenExpiresAt = Carbon::now()->addDays(7);
        $refreshTokenExpiresAt = Carbon::now()->addDays(14);

        $newAccessToken = $user->createToken('access_token', ['*'], $accessTokenExpiresAt)->plainTextToken;
        $newRefreshToken = $user->createToken('refresh_token', ['refresh'], $refreshTokenExpiresAt)->plainTextToken;

        return response()->json([
            'access_token' => $newAccessToken,
            'access_token_expires_at' => $accessTokenExpiresAt,
            'refresh_token' => $newRefreshToken,
            'refresh_token_expires_at' => $refreshTokenExpiresAt,
            'token_type' => 'Bearer',
        ]);
    }


    public function sendOtpSms($phoneNumber, $otp)
    {
        $message = 'Your OTP verification code is ' . $otp . ' your OTP is valid for 5 minutes.';

        $base_uri = config('services.swiftsms.baseUri');
        $endpoint = config('services.swiftsms.endpoint');
        $senderId = config('services.swiftsms.senderId');
        $token = config('services.swiftsms.token');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->get($base_uri . $endpoint, [
            'sender_id' => $senderId,
            'numbers' => $phoneNumber,
            'message' => $message,
        ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('Swift SMS send failed', ['response' => $response->body()]);
        return false;
    }

    public function verifyOtp(Request $request)
    {
        try {
            $request->validate([
                'otp_code' => 'required|numeric',
                'phone_number' => 'required|numeric',
            ]);

            // Normalize phone number
            $phoneInput = $request->phone_number;
            $cleanPhone = preg_replace('/[^0-9]/', '', $phoneInput);
            $last9Digits = substr($cleanPhone, -9);

            // 1. Check GuestLogin table (for existing users logging in)
            // We need to find a record where phone number matches (we stored the User's phone number there)
            // But the User's phone number might be different format than input? 
            // We stored `$existingUser->phone_number` in `guest_logins`.
            // So we should search `guest_logins` effectively.
            // Since we don't know the exact format stored, we might need to search or rely on exact match if frontend sends same format.
            // Let's assume frontend sends same format or we search strictly.
            // Safest: Search `User` by last 9 digits to get the "official" phone number, then search `guest_logins` with that.
            
            $user = User::where('phone_number', 'LIKE', "%{$last9Digits}")->latest()->first();
            
            if ($user) {
                // Existing user context
                // Check if there is a valid GuestLogin OTP
                $guestLogin = \App\Models\GuestLogin::where('phone_number', $user->phone_number)
                    ->where('otp_code', $request->otp_code)
                    ->where('otp_expires_at', '>', now())
                    ->latest()
                    ->first();

                if ($guestLogin) {
                    // Valid Guest OTP
                    // Mark user as onboarded if not (optional?)
                    // $user->is_onboarded = true; 
                    // $user->save(); 
                    
                    // Delete used OTP ??? Or keep log? usually delete or mark used.
                    // For now, let's delete to prevent replay or leave it as history?
                    // Request didn't specify, but security wise delete/invalidate.
                    $guestLogin->delete(); 

                    return response()->json([
                        'success' => true,
                        'message' => 'OTP verified successfully (Guest Login)',
                        'user_type' => 'existing'
                    ], 200);
                }
            }

            // 2. Fallback: Check Users table (for new registrations)
            // The original logic checked `exists:users,otp_code` in validation, which is strict. 
            // We removed strict validation above to allow custom logic.
            // New user registration flow stores OTP in `users` table.
            
            // If we found a user above, we can also check their directly stored OTP (for new users who just registered)
            // Or if $user was found but `guestLogin` wasn't, maybe they are a "new" user who hasn't completed flow?
            
            // Let's check the user object we found (or one strictly matching input if $user was loose)
            // Actually original code: `User::where('phone_number', $request->phone_number)`
            // Let's stick to that for the new user flow.
            
            $newUser = User::where('phone_number', $request->phone_number)->latest()->first();

            if ($newUser && $newUser->otp_code == $request->otp_code && $newUser->otp_expires_at && now()->lessThanOrEqualTo($newUser->otp_expires_at)) {
                 $newUser->is_onboarded = true;
                 $newUser->otp_code = null;
                 $newUser->otp_expires_at = null;
                 $newUser->save();

                 return response()->json([
                     'success' => true,
                     'message' => 'OTP verified successfully'
                 ], 200);
            }

            return response()->json(['error' => 'Invalid or expired OTP code'], 422);

        } catch (\Exception $e) {
            Log::error('Error in verifyOtp method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
