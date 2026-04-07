<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\OtpVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Send OTP to the given mobile number.
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile_no' => 'required|string|min:10|max:15',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user = User::where('mobile_no', $request->mobile_no)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found with this mobile number.'], 404);
        }

        $otp = 654321; //rand(100000, 999999);
        
        // Update or create OTP verification record
        OtpVerification::updateOrCreate(
            ['identifier' => $request->mobile_no, 'type' => 'login'],
            [
                'otp' => $otp,
                'is_verified' => false,
                'expires_at' => Carbon::now()->addMinutes(5),
            ]
        );

        Log::info("OTP for {$request->mobile_no}: {$otp}");

        return response()->json([
            'message' => 'OTP sent successfully.',
            'otp' => $otp,
        ]);
    }

    /**
     * Login using mobile number and OTP.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile_no' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $otpVerification = OtpVerification::where('identifier', $request->mobile_no)
            ->where('type', 'login')
            ->where('otp', $request->otp)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otpVerification) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 401);
        }

        $user = User::where('mobile_no', $request->mobile_no)->first();

        if (!$user) {
            return response()->json(['message' => 'User account not found.'], 404);
        }

        // Mark as verified
        $otpVerification->update(['is_verified' => true]);

        // $tokenName = $request->device_name ?: 'auth_token';
        // $token = $user->createToken($tokenName)->plainTextToken;

        // // Saving token to remember_token as per request
        // $user->remember_token = $token;
        // $user->save();

        $tokenName = $request->device_name ?: 'api-token';
        $validityDays = 10;
        $token = $user->createToken($tokenName, ['*'], now()->addDays($validityDays));
        $plainToken = $token->plainTextToken;
        $user->remember_token = $plainToken;
        $user->update();

        return response()->json([
            'message' => 'Login successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'token_validity' => (string) $validityDays,
            'user' => $user,
        ]);
    }

    public function refreshToken(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();

        $token = $user->createToken('api-token', ['*'], now()->addDays(10));
        $plainToken = $token->plainTextToken;
        $user->remember_token = $plainToken;
        $user->update();

        return response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'token' => $plainToken,
            'tokenType' => 'Bearer',
        ]);
    }
}
