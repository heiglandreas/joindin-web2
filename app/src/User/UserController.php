<?php
namespace User;

use Application\BaseController;
use Application\CacheService;
use Symfony\Component\Form\FormError;
use Slim\Slim;
use Talk\TalkDb;
use Talk\TalkApi;
use Event\EventDb;
use Event\EventApi;

class UserController extends BaseController
{
    /**
     * Routes implemented by this class
     *
     * @param \Slim $app Slim application instance
     *
     * @return void
     */
    protected function defineRoutes(\Slim\Slim $app)
    {
        $app->get('/user/logout', array($this, 'logout'))->name('user-logout');
        $app->map('/user/login', array($this, 'login'))->via('GET', 'POST')->name('user-login');
        $app->map('/user/register', array($this, 'register'))->via('GET', 'POST')->name('user-register');
        $app->get('/user/verification', array($this, 'verification'))->name('user-verification');
        $app->map('/user/resend-verification', array($this, 'resendVerification'))
            ->via('GET', 'POST')->name('user-resend-verification');
        $app->map('/user/:username', array($this, 'profile'))->via('GET', 'POST')->name('user-profile');
    }

    /**
     * Login page
     *
     * @return void
     */
    public function login()
    {
        $config = $this->application->config('oauth');
        $request = $this->application->request();

        $error = false;
        if ($request->isPost()) {
            // handle submission of login form
        
            // make a call to the api with granttype=password
            $username = $request->post('username');
            $password = $request->post('password');
            $redirect = $request->post('redirect');
            $clientId = $config['client_id'];
            $clientSecret = $config['client_secret'];

            $authApi = new AuthApi($this->cfg, $this->accessToken);
            $result = $authApi->login($username, $password, $clientId, $clientSecret);

            if (false === $result) {
                $error = true;
            } else {
                session_regenerate_id(true);
                $_SESSION['access_token'] = $result->access_token;
                $this->accessToken = $_SESSION['access_token'];

                // now get users details
                $keyPrefix = $this->cfg['redisKeyPrefix'];
                $cache = new CacheService($keyPrefix);
                $userApi = new UserApi($this->cfg, $this->accessToken, new UserDb($cache));
                $user = $userApi->getUser($result->user_uri);
                if ($user) {
                    $_SESSION['user'] = $user;
                    if (empty($redirect) || strpos($redirect, '/user/login') === 0) {
                        $this->application->redirect('/');
                    } else {
                        $this->application->redirect($redirect);
                    }
                } else {
                    unset($_SESSION['access_token']);
                }
            }
        }

        $this->render('User/login.html.twig', array('error' => $error));
    }

    /**
     * Registration page
     *
     * @return void
     */
    public function register()
    {
        $request = $this->application->request();

        /** @var FormFactoryInterface $factory */
        $factory = $this->application->formFactory;
        $form    = $factory->create(new RegisterFormType());

        if ($request->isPost()) {
            $form->submit($request->post($form->getName()));

            if ($form->isValid()) {
                $success = $this->registerUserUsingForm($form);

                if ($success) {
                    $this->application->flash(
                        'message',
                        "User created successfully. Please check your email to verify your account before logging in"
                    );
                    $this->application->redirect('/');
                }
            }
        }

        $this->render(
            'User/register.html.twig',
            array(
                'form' => $form->createView(),
            )
        );
    }

    /**
     * Submits the form data to the API and returns info for use by register()!
     *
     * Should an error occur will this method append an error message to the form's error collection.
     *
     * @param Form $form
     *
     * @return mixed
     */
    protected function registerUserUsingForm($form)
    {
        $values = $form->getData();
        $keyPrefix = $this->cfg['redisKeyPrefix'];
        $cache = new CacheService($keyPrefix);
        $userApi = new UserApi($this->cfg, $this->accessToken, new UserDb($cache));

        $result = false;
        try {
            $result = $userApi->register($values);
        } catch (\Exception $e) {
            $form->addError(
                new FormError('An error occurred while registering you: ' . $e->getMessage())
            );
        }

        return $result;
    }

    /**
     * Log out
     *
     * @return void
     */
    public function logout()
    {
        if (isset($_SESSION['user'])) {
            unset($_SESSION['user']);
        }
        if (isset($_SESSION['access_token'])) {
            unset($_SESSION['access_token']);
        }
        session_regenerate_id(true);
        $this->application->redirect('/');
    }

    /**
     * Accept a user's email verification
     *
     * @return void
     */
    public function verification()
    {
        $request = $this->application->request();

        $token = $request->get('token');
        $keyPrefix = $this->cfg['redisKeyPrefix'];
        $cache = new CacheService($keyPrefix);
        $userApi = new UserApi($this->cfg, $this->accessToken, new UserDb($cache));

        $result = false;
        try {
            $result = $userApi->verify($token);
            $this->application->flash('message', "Thank you for verifying your email address. You can now log in.");
        } catch (\Exception $e) {
            $this->application->flash('error', "Sorry, your verification link was invalid.");
        }

        $this->application->redirect('/user/login');

    }

    public function resendVerification()
    {
        $request = $this->application->request();

        /** @var FormFactoryInterface $factory */
        $factory = $this->application->formFactory;
        $form    = $factory->create(new EmailVerificationFormType());

        if ($request->isPost()) {
            $form->submit($request->post($form->getName()));

            if ($form->isValid()) {
                $values = $form->getData();
                $email = $values['email'];

                $keyPrefix = $this->cfg['redisKeyPrefix'];
                $cache = new CacheService($keyPrefix);
                $userApi = new UserApi($this->cfg, $this->accessToken, new UserDb($cache));

                $result = false;
                try {
                    $result = $userApi->reverify($email);
                    if ($result) {
                        $this->application->flash(
                            'message',
                            'We have resent your welcome email. Please check your email to verify your account before logging in.'
                        );
                        $this->application->redirect('/user/login');
                    }
                } catch (\Exception $e) {
                    $form->addError(
                        new FormError('An error occurred: ' . $e->getMessage())
                    );
                }

            }
        }

        $this->render(
            'User/emailverification.html.twig',
            array(
                'form' => $form->createView(),
            )
        );
    }

    /**
     * User profile page
     *
     * @param  string $username User's username
     * @return void
     */
    public function profile($username)
    {
        $keyPrefix = $this->cfg['redisKeyPrefix'];
        $cache = new CacheService($keyPrefix);
        $userDb = new UserDb($cache);
        $userApi = new UserApi($this->cfg, $this->accessToken, $userDb);

        $userInfo = $userDb->load('username', $username);
        if ($userInfo) {
            $user = $userApi->getUser($userInfo['uri']);
        } else {
            $user = $userApi->getUserByUsername($username);
            if (!$user) {
                Slim::getInstance()->notFound();
            }
            $userDb->save($user);
        }

        $talkDb = new TalkDb($cache);
        $talkApi = new TalkApi($this->cfg, $this->accessToken, $talkDb);
        $eventDb = new EventDb($cache);
        $eventApi = new EventApi($this->cfg, $this->accessToken, $eventDb);

        $eventInfo = array(); // look up an event's name and url_friendly_name from its uri
        $talkInfo = array(); // look up a talk's url_friendly_talk_title from its uri

        $talkCollection = $talkApi->getCollection($user->getTalksUri(), ['verbose' => 'yes', 'resultsperpage' => 5]);
        $talks = false;
        if (isset($talkCollection['talks'])) {
            $talks = $talkCollection['talks'];
            foreach ($talks as $talk) {
                // look up event's name & url_friendly_name from the API
                if (!isset($eventInfo[$talk->getEventUri()])) {
                    $event = $eventApi->getEvent($talk->getEventUri());
                    if ($event) {
                        $eventDb->save($event);
                        $eventInfo[$talk->getApiUri()]['url_friendly_name'] = $event->getUrlFriendlyName();
                        $eventInfo[$talk->getApiUri()]['name'] = $event->getName();
                    }
                }
            }
        }

        $eventsCollection = $eventApi->queryEvents($user->getAttendedEventsUri() . '?verbose=yes&resultsperpage=5');
        $events = false;
        if (isset($eventsCollection['events'])) {
            $events = $eventsCollection['events'];
        }

        $hostedEventsCollection = $eventApi->queryEvents($user->getHostedEventsUri() . '?verbose=yes&resultsperpage=5');
        $hostedEvents = false;
        if (isset($hostedEventsCollection['events'])) {
            $hostedEvents = $hostedEventsCollection['events'];
        }

        $talkComments = $talkApi->getComments($user->getTalkCommentsUri(), true, 5);
        foreach ($talkComments as $comment) {
            if (isset($talkInfo[$comment->getTalkUri()])) {
                continue;
            }
            $talk = $talkApi->getTalk($comment->getTalkUri());
            if ($talk) {
                $talkInfo[$comment->getTalkUri()]['url_friendly_talk_title'] = $talk->getUrlFriendlyTalkTitle();
                $talkDb->save($talk, $talk->getEventUri());

                // look up event's name & url_friendly_name from the API
                if (!isset($eventInfo[$talk->getEventUri()])) {
                    $event = $eventApi->getEvent($talk->getEventUri());
                    if ($event) {
                        $eventDb->save($event);
                        $eventInfo[$talk->getApiUri()]['url_friendly_name'] = $event->getUrlFriendlyName();
                        $eventInfo[$talk->getApiUri()]['name'] = $event->getName();
                    }
                }
            }
        }

        echo $this->render(
            'User/profile.html.twig',
            array(
                'thisUser'         => $user,
                'talks'            => $talks,
                'eventInfo'        => $eventInfo,
                'talkInfo'         => $talkInfo,
                'events'           => $events,
                'hostedEvents'     => $hostedEvents,
                'talkComments'     => $talkComments,
            )
        );
    }
}
