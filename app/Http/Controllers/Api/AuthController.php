<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\OtpVerification;
use App\Helpers\OtpHelper;
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

        // Generate and store OTP
        $otp = OtpHelper::generateAndStoreOtp('login', $request->mobile_no, 6, 5);

        // Send OTP via SMS
        $otpHelper = new OtpHelper();
        $smsResponse = $otpHelper->sendOtp($request->mobile_no, $otp);

        Log::info('OTP Send Response', [
            'phone' => $request->mobile_no,
            'otp' => $otp,
            'sms_response' => $smsResponse
        ]);

        return response()->json([
            'message' => 'OTP sent successfully.',
            'otp' => $otp,
            'sms_status' => $smsResponse,
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

        // Verify OTP using OtpHelper
        $verificationResponse = OtpHelper::verifyOtp('login', $request->mobile_no, $request->otp);
        $verificationData = $verificationResponse->getData();

        if ($verificationData->status !== 'success') {
            return $verificationResponse;
        }

        $user = User::where('mobile_no', $request->mobile_no)->select('id', 'name', 'email', 'mobile_no', 'category_id', 'role_id', 'team_id', 'refresh_token', 'is_active', 'created_by', 'updated_by', 'device_id', 'ip_address')->first();

        if (!$user) {
            return response()->json(['message' => 'User account not found.'], 404);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account is inactive. Login denied.'], 403);
        }


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
            // 'role' => $request->mobile_no == '9999999999' ? 4 : 6,
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
