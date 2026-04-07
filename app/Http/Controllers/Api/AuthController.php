<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Member;
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

        $member = Member::where('mobile_no', $request->mobile_no)->first();

        // If member doesn't exist, we can create one or return error. 
        // For BNI, let's assume they should exist.
        if (!$member) {
            return response()->json(['message' => 'Member not found with this mobile number.'], 404);
        }

        $otp = 654321; //rand(100000, 999999);
        $member->update([
            'otp' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(5),
        ]);

        // Simulating SMS sending
        Log::info("OTP for {$request->mobile_no}: {$otp}");

        return response()->json([
            'message' => 'OTP sent successfully.',
            'otp' => $otp, // Returning OTP for testing as per plan
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

        $member = Member::where('mobile_no', $request->mobile_no)
            ->where('otp', $request->otp)
            ->where('otp_expires_at', '>', Carbon::now())
            ->first();

        if (!$member) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 401);
        }

        // Clear OTP after login
        $member->update([
            'otp' => null,
            'otp_expires_at' => null,
        ]);

        $token = $member->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'member' => $member,
        ]);
    }
}
