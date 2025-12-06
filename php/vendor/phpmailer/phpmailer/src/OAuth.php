<?php

/**
 * PHPMailer - PHP email creation and transport class.
 * PHP Version 5.5.
 *
 * @see       https://github.com/PHPMailer/PHPMailer/ The PHPMailer GitHub project
 *
 * @author    Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 * @author    Jim Jagielski (jimjag) <jimjag@gmail.com>
 * @author    Andy Prevost (codeworxtech) <codeworxtech@users.sourceforge.net>
 * @author    Brent R. Matzelle (original founder)
 * @copyright 2012 - 2020 Marcus Bointon
 * @copyright 2010 - 2012 Jim Jagielski
 * @copyright 2004 - 2009 Andy Prevost
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 * @note      This program is distributed in the hope that it will be useful - WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace PHPMailer\PHPMailer;

use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use PHPMailer\PHPMailer\Exception;

/**
 * OAuth - OAuth2 authentication wrapper class.
 * Uses the oauth2-client package from the League of Extraordinary Packages.
 *
 * @see     https://oauth2-client.thephpleague.com
 *
 * @author  Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 */
class OAuth implements OAuthTokenProvider
{
    /**
     * An instance of the League OAuth Client Provider.
     *
     * @var AbstractProvider
     */
    protected $provider;

    /**
     * The current OAuth access token.
     *
     * @var AccessToken
     */
    protected $oauthToken;

    /**
     * The user's email address, usually used as the login ID
     * and also the from address when sending email.
     *
     * @var string
     */
    protected $oauthUserEmail = '';

    /**
     * The client secret, generated in the app definition of the service you're connecting to.
     *
     * @var string
     */
    protected $oauthClientSecret = '';

    /**
     * The client ID, generated in the app definition of the service you're connecting to.
     *
     * @var string
     */
    protected $oauthClientId = '';

    /**
     * The refresh token, used to obtain new AccessTokens.
     *
     * @var string
     */
    protected $oauthRefreshToken = '';

    /**
     * OAuth constructor.
     *
     * @param array $options Associative array containing
     *                       `provider`, `userName`, `clientSecret`, `clientId` and `refreshToken` elements
     */
    public function __construct($options)
    {
        foreach (['provider', 'userName', 'clientSecret', 'clientId', 'refreshToken'] as $key) {
            if (!array_key_exists($key, $options)) {
                throw new Exception("OAuth option missing: {$key}");
            }
        }
        if (!$options['provider'] instanceof AbstractProvider) {
            throw new Exception('OAuth provider must implement AbstractProvider');
        }
        $this->provider = $options['provider'];
        $this->oauthUserEmail = (string) $options['userName'];
        $this->oauthClientSecret = (string) $options['clientSecret'];
        $this->oauthClientId = (string) $options['clientId'];
        $this->oauthRefreshToken = (string) $options['refreshToken'];
    }

    /**
     * Get a new RefreshToken.
     *
     * @return RefreshToken
     */
    protected function getGrant()
    {
        return new RefreshToken();
    }

    /**
     * Get a new AccessToken.
     *
     * @return AccessToken
     */
    protected function getToken()
    {
        try {
            return $this->provider->getAccessToken(
                $this->getGrant(),
                ['refresh_token' => $this->oauthRefreshToken]
            );
        } catch (\Throwable $e) {
            throw new Exception('OAuth token retrieval failed', 0, $e);
        }
    }

    /**
     * Generate a base64-encoded OAuth token.
     *
     * @return string
     */
    public function getOauth64()
    {
        //Get a new token if it's not available or has expired
        if (null === $this->oauthToken || $this->oauthToken->hasExpired()) {
            $this->oauthToken = $this->getToken();
        }

        return base64_encode(
            'user=' .
            $this->oauthUserEmail .
            "\001auth=Bearer " .
            $this->oauthToken .
            "\001\001"
        );
    }

    /**
     * Hide sensitive credentials from debug output.
     *
     * @return array
     */
    public function __debugInfo()
    {
        return [
            'provider' => is_object($this->provider) ? get_class($this->provider) : null,
            'oauthUserEmail' => $this->oauthUserEmail,
            'hasClientId' => $this->oauthClientId !== '',
            'hasClientSecret' => $this->oauthClientSecret !== '',
            'hasRefreshToken' => $this->oauthRefreshToken !== '',
        ];
    }
}
