# Single Sign-On (SSO) Setup Guide

Wave Networks Core supports two SSO protocols:

- **OAuth 2.0** — Google, GitHub, Facebook
- **SAML 2.0** — Shibboleth, InCommon, Azure AD, Okta, or any standard SAML IdP

Both are configured entirely through the admin panel. No code changes or config file edits are needed.

---

## OAuth 2.0

### Supported Providers

| Provider | Library | Scopes |
|----------|---------|--------|
| Google | `league/oauth2-google` | email, profile |
| GitHub | `league/oauth2-github` | (default) |
| Facebook | `league/oauth2-facebook` | email |

### Step 1: Create OAuth App Credentials

#### Google

1. Go to [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Create an OAuth 2.0 Client ID (Web application)
3. Set the authorized redirect URI to:
   ```
   https://yourdomain.com/admin/auth/oauth_callback.php
   ```
4. Copy the **Client ID** and **Client Secret**

#### GitHub

1. Go to [GitHub Developer Settings](https://github.com/settings/developers)
2. Create a new OAuth App
3. Set the authorization callback URL to:
   ```
   https://yourdomain.com/admin/auth/oauth_callback.php
   ```
4. Copy the **Client ID** and generate a **Client Secret**

#### Facebook

1. Go to [Meta for Developers](https://developers.facebook.com/apps/)
2. Create a new app, add "Facebook Login" product
3. Under Facebook Login > Settings, add the valid OAuth redirect URI:
   ```
   https://yourdomain.com/admin/auth/oauth_callback.php
   ```
4. Copy the **App ID** and **App Secret**

### Step 2: Add Credentials to Config

**Docker (.env):**
```
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
```

**Shared hosting (config/config.php):**
```php
$google_client_id     = 'your_client_id';
$google_client_secret = 'your_client_secret';
$github_client_id     = 'your_client_id';
$github_client_secret = 'your_client_secret';
$facebook_app_id      = 'your_app_id';
$facebook_app_secret  = 'your_app_secret';
```

### Step 3: Enable in Admin Panel

1. Log in as an admin
2. Go to **OAuth Providers** in the sidebar
3. Select the provider, enter Client ID and Client Secret, click **Add Provider**
4. Click **Enable** to activate it

The provider's login button will immediately appear on the login page.

### How OAuth Login Works

1. User clicks "Sign in with Google" (or GitHub/Facebook) on the login page
2. They are redirected to the provider's consent screen
3. After granting access, they are redirected back to `auth/oauth_callback.php`
4. Core exchanges the authorization code for an access token, fetches the user's email and name
5. If the email matches an existing user, their account is linked and they are logged in
6. If no user exists and registration is not closed, a new account is auto-created
7. If the user has 2FA enabled, they are prompted for their TOTP code before completing login

---

## SAML 2.0

SAML support is designed for institutional identity providers like Shibboleth and InCommon federation members. Each provider is configured individually with its own IdP metadata, attribute mapping, and security settings.

### Terminology

| Term | Meaning |
|------|---------|
| **IdP** | Identity Provider — the institution's login server (e.g. Shibboleth) |
| **SP** | Service Provider — your Wave Networks installation |
| **ACS** | Assertion Consumer Service — the URL where the IdP sends the SAML response |
| **Entity ID** | Unique identifier for an IdP or SP in the SAML trust relationship |
| **SSO URL** | The IdP endpoint that initiates the login flow |
| **SLO URL** | Single Logout URL (optional) |
| **X.509 Certificate** | The IdP's signing certificate, used to verify SAML assertions |

### Step 1: Get IdP Metadata

Contact your institution's identity team or find the metadata URL. You need:

- **IdP Entity ID** — e.g. `https://idp.umich.edu/shibboleth`
- **IdP SSO URL** — e.g. `https://idp.umich.edu/idp/profile/SAML2/Redirect/SSO`
- **IdP SLO URL** (optional) — e.g. `https://idp.umich.edu/idp/profile/SAML2/Redirect/SLO`
- **IdP X.509 Certificate** — the signing certificate from the metadata XML

For InCommon members, metadata is published at [https://md.incommon.org/](https://md.incommon.org/).

### Step 2: Add Provider in Admin Panel

1. Log in as an admin
2. Go to **SAML Providers** in the sidebar
3. Fill in the form:

| Field | Value |
|-------|-------|
| **Display Name** | What users see on the login button (e.g. "University of Michigan Health") |
| **URL Slug** | Short URL-safe identifier, lowercase with hyphens only (e.g. `umich-health`) |
| **IdP Entity ID** | From the IdP metadata |
| **IdP SSO URL** | From the IdP metadata |
| **IdP SLO URL** | From the IdP metadata (optional) |
| **IdP X.509 Certificate** | Paste the full certificate (PEM headers are stripped automatically) |

4. Click **Add Provider**

### Step 3: Register Your SP with the IdP

The IdP admin needs your SP metadata. Give them this URL:

```
https://yourdomain.com/admin/auth/saml_metadata.php?provider=your-slug
```

This serves a standard SAML SP metadata XML document containing:

- **SP Entity ID** — auto-generated from the metadata URL (or custom if set)
- **ACS URL** — `https://yourdomain.com/admin/auth/saml_callback.php?acs=your-slug`
- **SLO URL** — `https://yourdomain.com/admin/auth/saml_callback.php?sls=your-slug`
- **NameID format** — emailAddress

The IdP admin will use this to register your application as a trusted SP.

### Step 4: Configure Attribute Mapping

The default OID mappings work for standard InCommon/Shibboleth setups:

| User Field | Default OID | eduPerson/inetOrgPerson |
|------------|-------------|------------------------|
| Email | `urn:oid:0.9.2342.19200300.100.1.3` | mail |
| First Name | `urn:oid:2.5.4.42` | givenName |
| Last Name | `urn:oid:2.5.4.4` | sn (surname) |
| Display Name | `urn:oid:2.16.840.1.113730.3.1.241` | displayName |

If your IdP uses different attribute names, update them in the provider's edit form.

**Fallback behavior:**
- If no email attribute is found, the SAML NameID is used as the email
- If no first/last name attributes are found, the display name attribute is split on the first space

### Step 5: Enable and Test

1. Click **Enable** on the provider row in the SAML Providers table
2. The login page will show a "Sign in with [Display Name]" button
3. Test the flow by clicking the button — you should be redirected to the IdP's login page
4. After authenticating at the IdP, you should be redirected back and logged in

### Security Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Require signed assertions** | On | The IdP must sign its SAML assertions. Leave on for production. |
| **Require encrypted NameID** | Off | The IdP must encrypt the NameID. Only enable if your IdP supports it and you have SP certificates configured. |
| **Authentication Context** | `PasswordProtectedTransport` | The minimum authentication level required. Default works for standard password login over TLS. |

### SP Entity ID

By default, the SP entity ID is auto-generated from the metadata URL:

```
https://yourdomain.com/admin/auth/saml_metadata.php?provider=your-slug
```

You can override this in the **SP Entity ID** field if your IdP requires a specific value.

### How SAML Login Works

1. User clicks "Sign in with [Institution]" on the login page
2. Core generates a SAML AuthnRequest and redirects to the IdP's SSO URL
3. The user authenticates at the IdP (institutional credentials, MFA, etc.)
4. The IdP posts a signed SAML Response to the ACS URL
5. Core validates the signature, extracts attributes using the OID mapping
6. If the email matches an existing user, their account is linked and they are logged in
7. If no user exists and registration is not closed, a new account is auto-created
8. If the user has 2FA enabled, they are prompted for their TOTP code before completing login

SAML users are stored with `saml:<slug>` in the `oauth_provider` column and the SAML NameID in the `oauth_id` column.

### Troubleshooting

**"Invalid SAML response" error:**
- Verify the IdP X.509 certificate is correct and current
- Check that the IdP's clock is synchronized (SAML has time-based validity)
- Ensure "Require signed assertions" matches what the IdP actually signs

**No email in the SAML response:**
- Check the attribute mapping OIDs match what the IdP releases
- Ask the IdP admin to confirm which attributes are released to your SP
- InCommon IdPs may require attribute release policies to be configured

**"Provider not found" on metadata URL:**
- Verify the slug in the URL matches the slug you entered when creating the provider

**Login button not appearing:**
- Make sure the provider status is "Enabled" in the admin panel

### Testing with SAMLtest.id

For development, you can test with [https://samltest.id](https://samltest.id), a free SAML test IdP:

1. Create a provider with:
   - **IdP Entity ID:** `https://samltest.id/saml/idp`
   - **IdP SSO URL:** `https://samltest.id/idp/profile/SAML2/Redirect/SSO`
   - **X.509 Certificate:** Download from samltest.id's metadata
2. Upload your SP metadata XML to samltest.id
3. Enable the provider and test the login flow
