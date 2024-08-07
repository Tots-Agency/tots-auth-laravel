<?php

namespace Tots\Auth\Http\Controllers\Basic;

use Illuminate\Http\Request;
use Tots\Auth\Models\TotsUser;
use Illuminate\Support\Facades\Hash;
use Tots\Auth\Models\TotsUserAttemp;
use Tots\Auth\Services\AuthService;
use Tots\Core\Exceptions\TotsException;

class LoginController extends \Illuminate\Routing\Controller
{

    /**
     *
     * @var AuthService
     */
    protected $service;

    public function __construct(AuthService $service)
    {
        $this->service = $service;
    }

    public function login(Request $request)
    {
        // Get Params
        $email = $request->input('email');
        $password = $request->input('password');
        // Search active user
        $user = $this->getActiveUser($request, $email, $password);
        // Process data
        $data = $user->toArray();
        // Generate Auth Token
        $data['token_type'] = 'bearer';
        $data['access_token'] = $this->service->generateAuthToken($user);
        
        return $data;
    }

    public function onlyInfo(Request $request)
    {
        // Get Params
        $email = $request->input('email');
        $password = $request->input('password');
        // Search active user
        $user = $this->getActiveUser($request, $email, $password);
        // Process data
        $data = $user->toArray();
        
        return $data;
    }

    protected function getActiveUser(Request $request, $email, $password)
    {
        $user = TotsUser::where('email', $email)->first();
        // Verify if account exist
        if($user === null){
            throw new TotsException('Item not exist.', 'not-found-email', 404);
        }
        // Verify if account is suspended
        if($user->status == TotsUser::STATUS_SUSPENDED){
            throw new TotsException('Your account is suspended, please contact the administrator.', 'suspended', 400);
        }
        // Verify max attempt
        $attemps = $this->verifyIfMaxAttempt($user) - 1;
        // Verify if password is correct
        if(!Hash::check($password, $user->password)){
            $this->createAttemp($request, $user);
            throw new TotsException('Incorrect username or password' . ($attemps != null ? ', you have ' . $attemps . ' attempts remaining' : '.'), 'wrong-credentials', 400);
        }

        return $user;
    }

    protected function verifyIfMaxAttempt(TotsUser $user)
    {
        $maxAttempt = $this->service->getMaxAttempt();
        if($maxAttempt == null||$maxAttempt == 0){
            return;
        }

        // Fetch all attemps in the last 24 hours
        $attemps = TotsUserAttemp::where('user_id', $user->id)
            ->where('created_at', '>=', (new \DateTime())->sub(new \DateInterval('PT1H')))
            ->count();

        if($attemps >= $this->service->getMaxAttempt()){
            throw new TotsException('You have entered your data wrong numerous times, try again within 1 hour', 'max-attempt', 400);
        }

        return $maxAttempt - $attemps;
    }

    protected function createAttemp(Request $request, TotsUser $user)
    {
        $attemp = new TotsUserAttemp();
        $attemp->user_id = $user->id;
        $attemp->ip = $request->getClientIp();
        $attemp->save();
    }
}
