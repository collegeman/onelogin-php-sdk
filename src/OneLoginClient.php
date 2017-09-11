<?php

namespace OneLogin\api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use OneLogin\api\util\Settings;
use OneLogin\api\util\Constants;
use OneLogin\api\models\App;
use OneLogin\api\models\Event;
use OneLogin\api\models\EventType;
use OneLogin\api\models\Group;
use OneLogin\api\models\MFA;
use OneLogin\api\models\OneLoginToken;
use OneLogin\api\models\RateLimit;
use OneLogin\api\models\Role;
use OneLogin\api\models\SAMLEndpointResponse;
use OneLogin\api\models\SessionTokenInfo;
use OneLogin\api\models\SessionTokenMFAInfo;
use OneLogin\api\models\User;

/**
 * OneLogin client implementation
 */
class OneLoginClient
{
    const VERSION = "1.0.0";

    const CUSTOM_USER_AGENT = "onelogin-php-sdk ".OneLoginClient::VERSION;

    /** @var GuzzleHttp\Client client  */
    protected $client;

    /** @var string $accessToken OAuth 2.0 Access Token */
    protected $accessToken;

    /** @var string $refreshToken OAuth 2.0 Refresh Token */
    protected $refreshToken;

    /** @var DateTime $expiration OAuth 2.0 Token expiration */
    protected $expiration;

    /** @var string $error Last error found */
    protected $error;

    /** @var string $errorDescription Description of last error found */
    protected $errorDescription;

    /** @var Settings $settings Settings object */
    protected $settings;

    /** @var String $userAgent the User-Agent to be used on requests */
    public $userAgent;

    /**
     * Create a new instance of Client.
     */
    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->client = new Client();
        $this->userAgent = OneLoginClient::CUSTOM_USER_AGENT;
    }

    /**
     * Clean any previous error registered at the client.
     */
    public function cleanError()
    {
        $this->error = null;
        $this->errorDescription = null;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getErrorDescription()
    {
        return $this->errorDescription;
    }

    protected function extractErrorMessageFromResponse($response)
    {
        $message = '';
        $content = json_decode((string) $response->getBody());
        if (property_exists($content, 'status')) {
            if (property_exists($content->status, 'message')) {
                $message = $content->status->message;
            } else if (property_exists($content->status, 'type')) {
                $message = $content->status->type;
            }
        }
        return $message;
    }

    protected function handleTokenResponse($response)
    {
        $token = null;
        $content = json_decode($response->getBody());
        if (property_exists($content, 'data')) {
            $token = new OneLoginToken($content->data[0]);
        }
        return $token;
    }

    protected function handleSessionTokenResponse($response)
    {
        $sessionToken = null;
        $content = json_decode($response->getBody());
        if (property_exists($content, 'status') && property_exists($content->status, 'message') && property_exists($content, 'data')) {
            if ($content->status->message == "Success") {
                $sessionToken = new SessionTokenInfo($content->data[0]);
            } else if ($content->status->message == "MFA is required for this user") {
                $sessionToken = new SessionTokenMFAInfo($content->data[0]);
            } else {
                new Exception("Status Message type not reognized: ".$content->status->message);
            }
        }
        return $sessionToken;
    }

    protected function handleSAMLEndpointResponse($response)
    {
        $samlEndpointResponse = null;
        $content = json_decode($response->getBody());
        if (property_exists($content, 'status') && property_exists($content, 'data') && property_exists($content->status, 'message') && property_exists($content->status, 'type')) {
            $type = $content->status->type;
            $message = $content->status->message;
            $samlEndpointResponse = new SAMLEndpointResponse($type, $message);
            
            if ($message == "Success") {
                $samlResponse = $content->data;
                $samlEndpointResponse->setSAMLResponse($samlResponse);
            } else {
                $mfa = new MFA($content->data[0]);
                $samlEndpointResponse->setMFA($mfa);
            }
        }
        return $samlEndpointResponse;
    }

    protected function handleDataResponse($response)
    {
        $data = null;
        $content = json_decode($response->getBody());
        if (property_exists($content, 'data')) {
            $data = $content->data;
        }
        return $data;
    }

    protected function handleOperationResponse($response)
    {
        $result = false;
        $content = json_decode($response->getBody());
        if (property_exists($content, 'status') && property_exists($content->status, 'type') && $content->status->type == "success") {
            $result = true;
        }
        return $result;
    }

    public function retrieveAppsFromXML($xmlContent)
    {
        $apps = array();
        $doc = new \DOMDocument();
        $doc->loadXML($xmlContent);
        $xpath = new \DOMXpath($doc);
        $appNodeList = $xpath->query("/apps/app");
        $attributes = array("id", "icon", "name", "provisioned", "extension_required", "personal", "login_id");
        $appData = array();
        foreach ($appNodeList as $appNode) {
            $appAttrs = $appNode->childNodes;
            foreach ($appAttrs as $appAttr) {
                if (in_array($appAttr->nodeName, $attributes)) {
                    $appData[$appAttr->nodeName] = $appAttr->textContent;
                }
            }
            $apps[] = new App((object) $appData);
        }
        return $apps;
    }

    protected function getAuthorization($bearer = true)
    {
        if ($bearer) {
            $authorization = "bearer:" . $this->accessToken;
        } else {
            $authorization = "client_id:" . $this->settings->getClientId() . ", client_secret:" . $this->settings->getClientSecret();
        }
        return $authorization;
    }

    protected function getBeforeCursor($response)
    {
        $beforeCursor = null;
        $content = json_decode($response->getBody());
        if (!empty($content)) {
            if (property_exists($content, 'pagination') && property_exists($content->pagination, 'before_cursor')) {
                $beforeCursor = $content->pagination->before_cursor;
            }
        }
        return $beforeCursor;
    }

    protected function getAfterCursor($response)
    {
        $afterCursor = null;
        $content = json_decode($response->getBody());
        if (!empty($content)) {
            if (property_exists($content, 'pagination') && property_exists($content->pagination, 'after_cursor')) {
                $afterCursor = $content->pagination->after_cursor;
            }
        }
        return $afterCursor;
    }

    public function isExpired()
    {
        $now = new \DateTime();
        return ($this->expiration != null) && ($now > $this->expiration);
    }

    protected function prepareToken()
    {
        if ($this->accessToken == null) {
            $this->getAccessToken();
        } else if ($this->isExpired()) {
            $this->refreshToken();
        }
    }

    ////////////////////////////////
    //  OAuth 2.0 Tokens Methods  //
    ////////////////////////////////

    /**
     *  Generates an access token and refresh token that you may use to
     *  call Onelogin's API methods.
     *
     * @see https://developers.onelogin.com/api-docs/1/oauth20-tokens/generate-tokens Generate Tokens documentation.
     */
    public function getAccessToken()
    {
        $this->cleanError();
        try {
            $url = $this->settings->getURL(Constants::TOKEN_REQUEST_URL);
            $authorization = $this->getAuthorization(false);

            $data = array(
                "grant_type" => "client_credentials"
            );
            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );

            $token = $this->handleTokenResponse($response);
            if (!empty($token)) {
                $this->accessToken = $token->getAccessToken();
                $this->refreshToken = $token->getRefreshToken();
                $this->expiration = $token->getExpiration();
            }
            return $token;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Refreshing tokens provides a new set of access and refresh tokens.
     *
     * @see https://developers.onelogin.com/api-docs/1/oauth20-tokens/refresh-tokens Refresh Tokens documentation
     */
    public function refreshToken()
    {
        $this->cleanError();
        try {
            if ($this->accessToken == null || $this->refreshToken == null) {
                throw new \Exception("Access token ot Refresh token not provided");
            }

            $url = $this->settings->getURL(Constants::TOKEN_REQUEST_URL);

            $headers = array(
                'User-Agent'=> $this->userAgent
            );

            $data = array(
                "grant_type" => "refresh_token",
                "access_token" => $this->accessToken,
                "refresh_token" => $this->refreshToken
            );

            $response = $this->client->post(
                $url,
                array(
                    'headers' => $headers,
                    'json' => $data
                )
            );

            $token = $this->handleTokenResponse($response);
            if (!empty($token)) {
                $this->accessToken = $token->getAccessToken();
                $this->refreshToken = $token->getRefreshToken();
                $this->expiration = $token->getExpiration();
            }
            return $token;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Revokes an access token and refresh token pair.
     *
     * @see https://developers.onelogin.com/api-docs/1/oauth20-tokens/revoke-tokens Revoke Tokens documentation
     */
    public function revokeToken()
    {
        $this->cleanError();
        try {
            if ($this->accessToken == null) {
                throw new \Exception("Access token not provided");
            }

            $url = $this->settings->getURL(Constants::TOKEN_REVOKE_URL);
            $authorization = $this->getAuthorization(false);

            $data = array(
                "access_token" => $this->accessToken
            );
            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );

            if ($response->getStatusCode() == 200) {
                $this->accessToken = null;
                $this->refreshToken = null;
                $this->expiration = null;
                return true;
            } else {
                $this->error = $response->getStatusCode();
                $this->errorDescription = $this->extractErrorMessageFromResponse($response);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Gets current rate limit details about an access token.
     *
     * @return RateLimit object
     *
     * @see https://developers.onelogin.com/api-docs/1/oauth20-tokens/get-rate-limit Get Rate Limit documentation
     */
    public function getRateLimit()
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $url = $this->settings->getURL(Constants::GET_RATE_URL);
            $authorization = $this->getAuthorization();

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            if ($data) {
                return new RateLimit($data);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    ////////////////////
    //  User Methods  //
    ////////////////////

    /**
     * Gets a list of User resources. (if no limit provided, by default get 50 elements)
     *
     * @param queryParameters
     *            Parameters to filter the result of the list
     *
     * @return Array of User
     *
     *
     * @see https://developers.onelogin.com/api-docs/1/users/get-users Get Users documentation
     */
    public function getUsers($queryParameters = null)
    {
        $this->cleanError();
        $this->prepareToken();
        $limit = 50;

        try {
            $authorization = $this->getAuthorization();
            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            if (!empty($queryParameters)) {
                if (!is_array($queryParameters)) {
                    new \Exception("Invalid value for queryParameters, must to be an indexed array");
                }

                if (!empty($queryParameters['limit'])) {
                    $limit = intVal($queryParameters['limit']);
                    if ($limit >= 50) {
                         unset($queryParameters['limit']);
                    }
                }
                $options['query'] = $queryParameters;
            }

            $url = $this->settings->getURL(Constants::GET_USERS_URL);

            $users = array();
            $afterCursor = null;
            while (!isset($response) || (count($users) < $limit && !empty($afterCursor))) {
                $response = $this->client->get(
                    $url,
                    $options
                );
                $data = $this->handleDataResponse($response);
            
                if (isset($data)) {
                    foreach ($data as $userData) {
                        if (count($users) < $limit) {
                            $users[] = new User($userData);
                        } else {
                            return $users;
                        }
                    }
                }

                $afterCursor = $this->getAfterCursor($response);
                if (!empty($afterCursor)) {
                    if (!isset($options['query'])) {
                        $options['query'] = array();
                    }
                    $options['query']['after_cursor'] = $afterCursor;
                }
            }
            if (count($users) > $limit) {
                $users = array_slice($users, 0, $limit);
            }
            return $users;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets User by ID.
     *
     * @param id
     *            Id of the user
     *
     * @return User
     *
     * @see https://developers.onelogin.com/api-docs/1/users/get-user-by-id Get User by ID documentation
     */
    public function getUser($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_USER_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new User($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets a list of apps accessible by a user, not including personal apps.
     *
     * @param id
     *            Id of the user
     *
     * @return List of Apps
     *
     * @see https://developers.onelogin.com/api-docs/1/users/get-apps-for-user Get Apps for a User documentation
     */
    public function getUserApps($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_APPS_FOR_USER_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            $apps = array();
            if (isset($data)) {
                foreach ($data as $appData) {
                    $apps[] = new App($appData);
                }
            }
            return $apps;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets a list of role IDs that have been assigned to a user.
     *
     * @param id
     *            Id of the user
     *
     * @return List of Role Ids
     *
     * @see https://developers.onelogin.com/api-docs/1/users/get-roles-for-user Get Roles for a User documentation
     */
    public function getUserRoles($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_ROLES_FOR_USER_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            $roleIds = array();
            if (!empty($data)) {
                $roleIds = $data[0];
            }
            return $roleIds;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets a list of all custom attribute fields (also known as custom user fields) that have been defined for OL account.
     *
     * @return List of custom attribute fields
     *
     * @see https://developers.onelogin.com/api-docs/1/users/get-custom-attributes Get Custom Attributes documentation
     */
    public function getCustomAttributes()
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_CUSTOM_ATTRIBUTES_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            $customAttributes = array();
            if (!empty($data)) {
                $customAttributes = $data[0];
            }
            return $customAttributes;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Creates an user
     *
     * @param userParams
     *            User data (firstname, lastname, email, username, company,
     *                       department, directory_id, distinguished_name,
     *                       external_id, group_id, invalid_login_attempts,
     *                       locale_code, manager_ad_id, member_of,
     *                       openid_name, phone, samaccountname, title,
     *                       userprincipalname)
     *
     * @return Created User
     *
     * @see https://developers.onelogin.com/api-docs/1/users/create-user Create User documentation
     */
    public function createUser($userParams)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::CREATE_USER_URL);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $userParams,
                    'headers' => $headers
                )
            );

            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new User($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Updates an user
     *
     * @param id
     *            Id of the user to be modified
     * @param userParams
     *            User data (firstname, lastname, email, username, company,
     *                       department, directory_id, distinguished_name,
     *                       external_id, group_id, invalid_login_attempts,
     *                       locale_code, manager_ad_id, member_of,
     *                       openid_name, phone, samaccountname, title,
     *                       userprincipalname)
     *
     * @return Updated User
     *
     * @see https://developers.onelogin.com/api-docs/1/users/update-user Update User by ID documentation
     */
    public function updateUser($id, $userParams)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::UPDATE_USER_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $userParams,
                    'headers' => $headers
                )
            );

            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new User($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Assigns Roles to User
     *
     * @param id
     *            Id of the user to be modified
     * @param roleIds
     *            Set to an array of one or more role IDs.
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/assign-role-to-user Assign Role to User documentation
     */
    public function assignRoleToUser($id, $roleIds)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::ADD_ROLE_TO_USER_URL, $id);

            $data = array(
                "role_id_array" => $roleIds,
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Remove Role from User
     *
     * @param id
     *            Id of the user to be modified
     * @param roleIds
     *            Set to an array of one or more role IDs.
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/remove-role-from-user Remove Role from User documentation
     */
    public function removeRoleFromUser($id, $roleIds)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::DELETE_ROLE_TO_USER_URL, $id);

            $data = array(
                "role_id_array" => $roleIds,
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Sets Password by ID Using Cleartext
     *
     * @param id
     *            Id of the user to be modified
     * @param password
     *            Set to the password value using cleartext.
     * @param passwordConfirmation
     *            Ensure that this value matches the password value exactly.
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/set-password-in-cleartext Set Password by ID Using Cleartext documentation
     */
    public function setPasswordUsingClearText($id, $password, $passwordConfirmation)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::SET_PW_CLEARTEXT, $id);

            $data = array(
                "password" => $password,
                "password_confirmation" => $passwordConfirmation
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Set Password by ID Using Salt and SHA-256
     *
     * @param id
     *            Id of the user to be modified
     * @param password
     *            Set to the password value using a SHA-256-encoded value.
     * @param passwordConfirmation
     *            This value must match the password value.
     * @param passwordAlgorithm
     *            Set to salt+sha256.
     * @param passwordSalt
     *            To provide your own salt value.
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/set-password-using-sha-256 Set Password by ID Using Salt and SHA-256 documentation
     */
    public function setPasswordUsingHashSalt($id, $password, $passwordConfirmation, $passwordAlgorithm, $passwordSalt = null)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::SET_PW_SALT, $id);

            $data = array(
                "password" => $password,
                "password_confirmation" => $passwordConfirmation,
                "password_algorithm" => $passwordAlgorithm
            );

            if (!empty($passwordSalt)) {
                $data["password_salt"] = $passwordSalt;
            }

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Set Custom Attribute Value
     *
     * @param id
     *            Id of the user to be modified
     * @param customAttributes
     *            Provide one or more key value pairs composed of the custom attribute field shortname and the value that you want to set the field to.
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/set-custom-attribute Set Custom Attribute Value documentation
     */
    public function setCustomAttributeToUser($id, $customAttributes)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::SET_CUSTOM_ATTRIBUTE_TO_USER_URL, $id);

            $data = array(
                "custom_attributes" => $customAttributes
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Log a user out of any and all sessions.
     *
     * @param id
     *            Id of the user to be logged out
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/log-user-out Log User Out documentation
     */
    public function logUserOut($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::LOG_USER_OUT_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Use this call to lock a user's account based on the policy assigned to
     * the user, for a specific time you define in the request, or until you
     * unlock it.
     *
     * @param id
     *            Id of the user to be locked
     * @param minutes
     *            Set to the number of minutes for which you want to lock the user account. (0 to delegate on policy)
         *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/lock-user-account Lock User Account documentation
     */
    public function lockUser($id, $minutes)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::LOCK_USER_URL, $id);

            $data = array(
                "locked_until" => $minutes
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->put(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /**
     * Deletes an user
     *
     * @param id
     *            Id of the user to be deleted
     *
     * @return true if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/delete-user Delete User by ID documentation
     */
    public function deleteUser($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::DELETE_USER_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->delete(
                $url,
                array(
                    'headers' => $headers
                )
            );

            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    //////////////////////////
    //  Login Page Methods  //
    //////////////////////////

    /**
     * Generates a session login token in scenarios in which MFA may or may not be required.
     * A session login token expires two minutes after creation.
     *
     * @param queryParams
     *            Query Parameters (username_or_email, password, subdomain, return_to_url,
     *                              ip_address, browser_id)
     * @param allowedOrigin
     *            Custom-Allowed-Origin-Header. Required for CORS requests only. Set to the Origin URI
     *            from which you are allowed to send a request using CORS.
     *
     * @return SessionTokenInfo or SessionTokenMFAInfo object if success
     *
     * @see https://developers.onelogin.com/api-docs/1/users/create-session-login-token Create Session Login Token documentation
     */
    public function createSessionLoginToken($queryParams, $allowedOrigin = '')
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::SESSION_LOGIN_TOKEN_URL);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            if (!empty($allowedOrigin)) {
                $headers['Custom-Allowed-Origin-Header-1'] = $allowedOrigin;
            }

            $response = $this->client->post(
                $url,
                array(
                    'json' => $queryParams,
                    'headers' => $headers
                )
            );

            $sesionToken = $this->handleSessionTokenResponse($response);
            return $sesionToken;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Verify a one-time password (OTP) value provided for multi-factor authentication (MFA).
     *
     * @param devideId
     *            Provide the MFA device_id you are submitting for verification.
     * @param stateToken
     *            Provide the state_token associated with the MFA device_id you are submitting for verification.
     * @param otpToken
     *            Provide the OTP value for the MFA factor you are submitting for verification.
     *
     * @return Session Token
     *
     * @see https://developers.onelogin.com/api-docs/1/users/verify-factor Verify Factor documentation
     */
    public function getSessionTokenVerified($devideId, $stateToken, $otpToken = null)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_TOKEN_VERIFY_FACTOR);

            $data = array(
                "device_id" => strval($devideId),
                "state_token" => $stateToken
            );

            if (!empty($otpToken)) {
                $data["otp_token"] = $otpToken;
            }

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );

            $sesionToken = $this->handleSessionTokenResponse($response);
            return $sesionToken;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Post a session token to this API endpoint to start a session and set a cookie to log a user into an app.
     *
     * @param sessionToken
     *            The session token
     *
     * @return Header 'Set-Cookie' value
     *
     * @see https://github.com/onelogin/onelogin-api-examples/blob/master/php/users/session_via_api_token.php Create Session Via API Token documentation
     */
    public function createSessionViaToken($sessionToken)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $url = $this->settings->getURL(Constants::SESSION_API_TOKEN_URL);

            $data = array(
                "session_token" => $sessionToken
            );

            $headers = array(
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'headers' => $headers,
                    'json' => $data
                )
            );

            $cookieHeader = null;
            $headers = $response->getHeaders();
            if (!empty($headers["Set-Cookie"])) {
                $cookieHeader = $headers["Set-Cookie"];
            }
            return $cookieHeader;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    ////////////////////
    //  Role Methods  //
    ////////////////////

    /**
     * Gets a list of Role resources. (if no limit provided, by default get 50 elements)
     *
     * @param queryParameters
     *            Parameters to filter the result of the list
     *
     * @return List of Role
     *
     * @see https://developers.onelogin.com/api-docs/1/roles/get-roles Get Roles documentation
     */
    public function getRoles($queryParameters = null)
    {
        $this->cleanError();
        $this->prepareToken();
        $limit = 50;

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_ROLES_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            if (!empty($queryParameters)) {
                if (!is_array($queryParameters)) {
                    new \Exception("Invalid value for queryParameters, must to be an indexed array");
                }

                if (!empty($queryParameters['limit'])) {
                    $limit = intVal($queryParameters['limit']);
                    if ($limit >= 50) {
                         unset($queryParameters['limit']);
                    }
                }
                $options['query'] = $queryParameters;
            }

            $roles = array();
            $afterCursor = null;
            while (!isset($response) || (count($roles) < $limit && !empty($afterCursor))) {
                $response = $this->client->get(
                    $url,
                    $options
                );
                $data = $this->handleDataResponse($response);
            
                if (isset($data)) {
                    foreach ($data as $roleData) {
                        if (count($roles) < $limit) {
                            $roles[] = new Role($roleData);
                        } else {
                            return $roles;
                        }
                    }
                }

                $afterCursor = $this->getAfterCursor($response);
                if (!empty($afterCursor)) {
                    if (!isset($options['query'])) {
                        $options['query'] = array();
                    }
                    $options['query']['after_cursor'] = $afterCursor;
                }
            }
            return $roles;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets Role by ID.
     *
     * @param id
     *            Id of the role
     *
     * @return Role
     *
     * @see https://developers.onelogin.com/api-docs/1/roles/get-role-by-id Get Role by ID documentation
     */
    public function getRole($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_ROLE_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new Role($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /////////////////////
    //  Event Methods  //
    /////////////////////

    /**
     * List of all OneLogin event types available to the Events API.
     *
     * @return List of EventType
     *
     * @see https://developers.onelogin.com/api-docs/1/events/event-types Get Event Types documentation
     */
    public function getEventTypes()
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_EVENT_TYPES_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            $eventTypes = array();
            if (!empty($data)) {
                foreach ($data as $eventTypeData) {
                    $eventTypes[] = new EventType($eventTypeData);
                }
            }
            return $eventTypes;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets a list of Event resources. (if no limit provided, by default get 50 elements)
     *
     * @param queryParameters
     *            Parameters to filter the result of the list
     *
     * @return List of Event
     *
     * @see https://developers.onelogin.com/api-docs/1/events/get-events Get Events documentation
     */
    public function getEvents($queryParameters = null)
    {
        $this->cleanError();
        $this->prepareToken();
        $limit = 50;

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_EVENTS_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            if (!empty($queryParameters)) {
                if (!is_array($queryParameters)) {
                    new \Exception("Invalid value for queryParameters, must to be an indexed array");
                }

                if (!empty($queryParameters['limit'])) {
                    $limit = intVal($queryParameters['limit']);
                    if ($limit >= 50) {
                         unset($queryParameters['limit']);
                    }
                }
                $options['query'] = $queryParameters;
            }

            $events = array();
            $afterCursor = null;
            while (!isset($response) || (count($events) < $limit && !empty($afterCursor))) {
                $response = $this->client->get(
                    $url,
                    $options
                );

                $data = $this->handleDataResponse($response);
            
                if (isset($data)) {
                    foreach ($data as $eventData) {
                        if (count($events) < $limit) {
                            $events[] = new Event($eventData);
                        } else {
                            return $events;
                        }
                    }
                }

                $afterCursor = $this->getAfterCursor($response);
                if (!empty($afterCursor)) {
                    if (!isset($options['query'])) {
                        $options['query'] = array();
                    }
                    $options['query']['after_cursor'] = $afterCursor;
                }
            }

            return $events;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets Event by ID.
     *
     * @param id
     *            Id of the event
     *
     * @return Event
     *
     * @see https://developers.onelogin.com/api-docs/1/events/get-event-by-id Get Event by ID documentation
     */
    public function getEvent($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_EVENT_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new Event($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Create an event in the OneLogin event log.
     *
     * @param eventParams
     *            Event Data (event_type_id, account_id, actor_system,
     *                        actor_user_id, actor_user_name, app_id,
     *                        assuming_acting_user_id, custom_message,
     *                        directory_sync_run_id, group_id, group_name,
     *                        ipaddr, otp_device_id, otp_device_name,
     *                        policy_id, policy_name, role_id, role_name,
     *                        user_id, user_name)
     *
     * @see https://developers.onelogin.com/api-docs/1/events/create-event Create Event documentation
     */
    public function createEvent($eventParams)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::CREATE_EVENT_URL);

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $eventParams,
                    'headers' => $headers
                )
            );

            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
        return false;
    }

    /////////////////////
    //  Group Methods  //
    /////////////////////

    /**
     * Gets a list of Group resources (element of groups limited with the limit parameter).
     *
     * @param limit
     *            Limit the number of groups returned (optional)
     *
     * @return List of Group
     *
     * @see https://developers.onelogin.com/api-docs/1/groups/get-groups Get Groups documentation
     */
    public function getGroups($limit = 50)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_GROUPS_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $options = array(
                'headers' => $headers
            );

            $groups = array();
            $afterCursor = null;
            while (!isset($response) || (count($groups) < $limit && !empty($afterCursor))) {
                $response = $this->client->get(
                    $url,
                    $options
                );
                $data = $this->handleDataResponse($response);
            
                if (isset($data)) {
                    foreach ($data as $groupData) {
                        if (count($groups) < $limit) {
                            $groups[] = new Group($groupData);
                        } else {
                            return $groups;
                        }
                    }
                }

                $afterCursor = $this->getAfterCursor($response);
                if (!empty($afterCursor)) {
                    if (!isset($options['query'])) {
                        $options['query'] = array();
                    }
                    $options['query']['after_cursor'] = $afterCursor;
                }
            }
            return $groups;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Gets Group by ID.
     *
     * @param id
     *            Id of the group
     *
     * @return Group
     *
     * @see https://developers.onelogin.com/api-docs/1/groups/get-group-by-id Get Group by ID documentation
     */
    public function getGroup($id)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_GROUP_URL, $id);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers
                )
            );
            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return new Group($data[0]);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    //////////////////////////////
    //  SAML Assertion Methods  //
    //////////////////////////////

    /**
     * Generates a SAML Assertion.
     *
     * @param usernameOrEmail
     *            username or email of the OneLogin user accessing the app
     * @param password
     *            Password of the OneLogin user accessing the app
     * @param appId
     *            App ID of the app for which you want to generate a SAML token
     * @param subdomain
     *            subdomain of the OneLogin account related to the user/app
     * @param ipAddress
     *            whitelisted IP address that needs to be bypassed (some MFA scenarios).
     *
     * @return SAMLEndpointResponse
     *
     * @see https://developers.onelogin.com/api-docs/1/saml-assertions/generate-saml-assertion Generate SAML Assertion documentation
     */
    public function getSAMLAssertion($usernameOrEmail, $password, $appId, $subdomain, $ipAddress = null)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GET_SAML_ASSERTION_URL);

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $data = array(
                "username_or_email" => $usernameOrEmail,
                "password" => $password,
                "app_id" => $appId,
                "subdomain"  => $subdomain
            );

            if (!empty($ipAddress)) {
                $data["ip_address"] = $ipAddress;
            }

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            $samlEndpointResponse = $this->handleSAMLEndpointResponse($response);
            return $samlEndpointResponse;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Verifies a one-time password (OTP) value provided for a second factor
     * when multi-factor authentication (MFA) is required for SAML authentication.
     *
     * @param appId
     *            App ID of the app for which you want to generate a SAML token
     * @param devideId
     *            Provide the MFA device_id you are submitting for verification.
     * @param stateToken
     *            Provide the state_token associated with the MFA device_id you are submitting for verification.
     * @param otpToken
     *            Provide the OTP value for the MFA factor you are submitting for verification.
     * @param urlEndpoint
     *            Specify an url where return the response.
     *
     * @return SAMLEndpointResponse
     *
     * @see https://developers.onelogin.com/api-docs/1/saml-assertions/verify-factor Verify Factor documentation
     */
    public function getSAMLAssertionVerifying($appId, $devideId, $stateToken, $otpToken = null, $urlEndpoint = null)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            if (empty($urlEndpoint)) {
                $url = $this->settings->getURL(Constants::GET_SAML_VERIFY_FACTOR);
            } else {
                $url = $urlEndpoint;
            }

            $headers = array(
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'User-Agent'=> $this->userAgent
            );

            $data = array(
                "app_id" => $appId,
                "device_id" => strval($devideId),
                "state_token" => $stateToken
            );

            if (!empty($otpToken)) {
                $data["otp_token"] = $otpToken;
            }

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );
            $samlEndpointResponse = $this->handleSAMLEndpointResponse($response);
            return $samlEndpointResponse;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    ////////////////////////////
    //  Invite Links Methods  //
    ////////////////////////////

    /**
     * Generates an invite link for a user that you have already created in your OneLogin account.
     *
     * @param email
     *            Set the email address of the user that you want to generate an invite link for.
     *
     * @return String with the link
     *
     * @see https://developers.onelogin.com/api-docs/1/invite-links/generate-invite-link Generate Invite Link documentation
     */
    public function generateInviteLink($email)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::GENERATE_INVITE_LINK_URL);

            $data = array(
                "email" => $email
            );

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );

            $data = $this->handleDataResponse($response);
            if (!empty($data)) {
                return $data[0];
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    /**
     * Sends an invite link to a user that you have already created in your OneLogin account.
     *
     * @param email
     *            Set to the email address of the user that you want to send an invite link for.
     * @param personal_email
     *            If you want to send the invite email to an email other than the
     *            one provided in email, provide it here. The invite link will be
     *            sent to this address instead.
     *
     * @return True if the mail with the link was sent
     *
     * @see https://developers.onelogin.com/api-docs/1/invite-links/send-invite-link Send Invite Link documentation
     */
    public function sendInviteLink($email, $personalEmail = null)
    {
        $this->cleanError();
        $this->prepareToken();

        try {
            $authorization = $this->getAuthorization();

            $url = $this->settings->getURL(Constants::SEND_INVITE_LINK_URL);

            $data = array(
                "email" => $email
            );

            if (!empty($personalEmail)) {
                $data["personal_email"] = $personalEmail;
            }

            $headers = array(
                'Authorization' => $authorization,
                'User-Agent'=> $this->userAgent
            );

            $response = $this->client->post(
                $url,
                array(
                    'json' => $data,
                    'headers' => $headers
                )
            );

            return $this->handleOperationResponse($response);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }

    ///////////////////////////
    //  Embed Apps   Method  //
    ///////////////////////////

    /**
     * Lists apps accessible by a OneLogin user.
     *
     * @param token
     *            Provide your embedding token.
     * @param email
     *            Provide the email of the user for which you want to return a list of embeddable apps.
     *
     * @return A list of Apps
     *
     * @see https://developers.onelogin.com/api-docs/1/embed-apps/get-apps-to-embed-for-a-user Get Apps to Embed for a User documentation
     */
    public function getEmbedApps($token, $email)
    {
        $this->cleanError();

        try {
            $url = Constants::EMBED_APP_URL;

            $data = array(
                "token" => $token,
                "email" => $email
            );

            $headers = array (
                'User-Agent'=> $this->userAgent
            );

            $apps = null;
            $response = $this->client->get(
                $url,
                array(
                    'headers' => $headers,
                    'json' => $data
                )
            );

            $xmlContent = $response->getBody()->getContents();
            if (!empty($xmlContent)) {
                $apps = $this->retrieveAppsFromXML($xmlContent);
            }
            return $apps;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->error = $response->getStatusCode();
            $this->errorDescription = $this->extractErrorMessageFromResponse($response);
        } catch (\Exception $e) {
            $this->error = 500;
            $this->errorDescription = $e->getMessage();
        }
    }
}