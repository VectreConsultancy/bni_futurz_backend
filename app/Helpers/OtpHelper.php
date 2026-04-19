<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class OtpHelper
{
    public static function generateAndStoreOtp($type, $identifier, $length = 6, $expiry = 5)
    {
        $otp = 654321; // Default OTP for testing purposes
        $whitelisted = ['7777777777', '9999999999', '6666666666', '8888888888'];

        if (config('app.env') == 'production' && !in_array($identifier, $whitelisted))
            $otp = random_int(pow(10, $length - 1), pow(10, $length) - 1);

        $expiryTime = Carbon::now()->addMinutes($expiry);

        DB::table('tbl_otp_verification')->insert([
            'type' => $type,
            'identifier' => $identifier,
            'otp' => $otp,
            'is_verified' => 0,
            'expires_at' => $expiryTime,
        ]);

        session(['current_OTP' => $otp]);
        return $otp;
    }

    public function sendSMS($mobileno, $otp)
    {
        $message = "$otp is your OTP for Login in App . Do Not Share it with Anyone. THINKERS PARADIZE";
        $authkey = "1b9712ca5c4d33055f562fd0887412e8";
        $senderid = "THNPRZ";
        $route = "4";
        $template_id = '1407173635710288985';

        $response = Http::get('http://shubhsms.com/apiv2', [
            'authkey' => $authkey,
            'senderid' => $senderid,
            'numbers' => $mobileno,
            'message' => $message,
            'route' => $route,
            'template_id' => $template_id
        ]);
        
        return $response->json();
    }

    public function sendOtp($identifier, $otp)
    {
        try {
            $response = null;
            $whitelisted = ['7777777777', '9999999999', '6666666666', '8888888888'];

            if (config('app.env') == 'production' && !in_array($identifier, $whitelisted))
                $response = $this->sendSMS($identifier, $otp);

            Log::info("OTP sent to $identifier: $otp", [
                'response' => $response,
                'phone' => $identifier
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to send OTP to $identifier", [
                'error' => $e->getMessage(),
                'otp' => $otp
            ]);
            throw $e;
        }
    }

    public static function verifyOtp($type, $identifier, $otp)
    {
        // Check if the OTP exists and is not already verified
        $record = DB::table('tbl_otp_verification')
            ->where('type', $type)
            ->where('identifier', $identifier)
            // ->where('otp', $otp)
            ->where('is_verified', 0)
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP or OTP has expired. Please enter the correct OTP.'
            ]);
        }

        // Check if the OTP has expired
        if (Carbon::now()->greaterThan(Carbon::parse($record->expires_at))) {
            DB::table('tbl_otp_verification')
                ->where('identifier', $identifier)
                ->where('otp', $otp)
                ->update(['is_verified' => 2]); // Mark as expired

            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired. Please try again.'
            ]);
        }

        if ($record->otp == $otp) {
            // If OTP is valid and not expired, mark it as verified
            $affectedRows = DB::table('tbl_otp_verification')
                ->where('id', $record->id)
                ->update(['is_verified' => 1]); // Mark as verified

            if ($affectedRows > 0) {
                session(['is_verified' => 1]);
                session(['sess_mobile' => $record->identifier]);
                return response()->json([
                    'status' => 'success',
                    'message' => 'OTP verified successfully.'
                ]);
            }
        } else {

            return response()->json([
                'status' => 'error',
                'message' => 'Error during OTP verification. Please enter the correct OTP.'
            ]);
        }
        return response()->json([
            'status' => 'error',
            'message' => 'Error during OTP verification. Please enter the correct OTP.'
        ]);
    }
}