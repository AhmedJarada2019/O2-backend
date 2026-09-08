<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PosRegister;
use App\Models\HospitalityDevice;

/**
 * CheckPosNetwork — Middleware لحماية مسارات الكاشير والضيافة
 * ──────────────────────────────────────────────────
 * يقوم بـ 3 فحوصات أمنية مع كل طلب:
 *   1. التأكد من وجود X-Device-UUID في الهيدر (الجهاز غير مفعّل)
 *   2. التأكد من أن حالة الجهاز لا تزال ACTIVE (لم يتم إلغاؤه)
 *   3. مقارنة نطاق IP الحالي مع Static IP الخاص بالفرع (Subnet /24)
 *
 * يدعم both POS registers و hospitality devices
 *
 * الاستخدام: 'check.pos.network'
 */
class CheckPosNetwork
{
    public function handle(Request $request, Closure $next): mixed
    {
        // ── 1. جلب UUID الجهاز من الهيدر ─────────────────────────
        $deviceUuid = $request->header('X-Device-UUID');

        if (!$deviceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الجهاز غير مفعّل! يُرجى تفعيل نقطة البيع أولاً عبر كود التفعيل.',
            ], 403);
        }

        // ── 2. البحث في نقطة البيع أولاً ──────────
        $register = PosRegister::where('device_uuid', $deviceUuid)
            ->with('branch')
            ->first();

        $device = null;
        $branch = null;

        if ($register) {
            $branch = $register->branch;
        } else {
            // ── 3. البحث في أجهزة الضيافة ──────────
            $device = HospitalityDevice::where('device_uuid', $deviceUuid)
                ->with('branch')
                ->first();

            if ($device) {
                $branch = $device->branch;
            }
        }

        // ── 4. هل الجهاز مسجل في قاعدة البيانات؟
        if (!$register && !$device) {
            return response()->json([
                'success' => false,
                'message' => 'معرّف الجهاز غير معروف في النظام! يُرجى إعادة التفعيل.',
            ], 403);
        }

        // ── 5. هل حالة الجهاز لا تزال ACTIVE؟
        if ($register && $register->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        if ($device && $device->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء تفعيل هذا الجهاز من قبل الإدارة! يُرجى مراجعة الإدارة لإعادة التفعيل.',
            ], 403);
        }

        // ── 6. فحص الشبكة (Static IP) ───────────────────────────
        // الشبكة الفعلية هون مضبوطة بقناع /23 (255.255.254.0) - مؤكد من
        // إعدادات الشبكة نفسها (السيرفر المركزي عنوانه 192.168.2.250/23،
        // يغطي المدى الكامل 192.168.2.0–192.168.3.255 كشبكة فيزيائية
        // واحدة). المقارنة القديمة (أول 3 أرقام بس، يعني /24) كانت ترفض
        // بالغلط أي جهاز واقع بالنص التاني من نفس الشبكة الحقيقية - مؤكد
        // فعليًا: جهاز POS-012 بعنوان 192.168.2.42 انحظر لأنه عنوان الفرع
        // المرجعي كان 192.168.3.30، رغم إنهم فعليًا بنفس الشبكة.
        if ($branch?->static_ip) {
            $clientIp = $request->ip();
            $branchIp = $branch->static_ip;

            $mask = ip2long('255.255.254.0');
            $clientLong = ip2long($clientIp);
            $branchLong = ip2long($branchIp);

            $sameNetwork = $clientLong !== false
                && $branchLong !== false
                && ($clientLong & $mask) === ($branchLong & $mask);

            if (!$sameNetwork) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، تم حظر الطلب! لا يمكن استخدام نقطة البيع من خارج شبكة الفرع الرسمية.',
                ], 403);
            }
        }

        // ── 7. فحص تطابق فرع المستخدم مع فرع الجهاز ─────────────
        $user = $request->user();
        if ($user && !$user->hasRole('super-admin')) {
            if ($register && (int) $user->branch_id !== (int) $register->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، أنت لا تنتمي لهذا الفرع! لا يمكنك استخدام هذا الجهاز.',
                ], 403);
            }
            if ($device && (int) $user->branch_id !== (int) $device->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، أنت لا تنتمي لهذا الفرع! لا يمكنك استخدام هذا الجهاز.',
                ], 403);
            }
        }

        // ── 8. كل الفحوصات سليمة → مرر الطلب ────────────────────
        return $next($request);
    }
}
