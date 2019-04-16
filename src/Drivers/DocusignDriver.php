<?php

namespace Helori\PhpSign\Drivers;

use DocuSign\eSign\Configuration;
use DocuSign\eSign\ApiClient;
use DocuSign\eSign\Api\AuthenticationApi;
use DocuSign\eSign\Api\AuthenticationApi\LoginOptions;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Api\EnvelopesApi\CreateEnvelopeOptions;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\EventNotification;

use Helori\PhpSign\Utilities\RestApiRequester;
use Helori\PhpSign\Utilities\DateParser;
use Helori\PhpSign\Elements\Scenario;
use Helori\PhpSign\Elements\Transaction;
use Helori\PhpSign\Elements\SignerResult;
use Helori\PhpSign\Elements\DocumentResult;
use Helori\PhpSign\Elements\Webhook;
use Helori\PhpSign\Exceptions\DriverAuthException;
use Helori\PhpSign\Exceptions\SignException;

use Firebase\JWT\JWT;

// https://admindemo.docusign.com/
// https://appdemo.docusign.com

class DocusignDriver implements DriverInterface
{
	/**
     * The Docusign API Requester
     *
     * @var \Helori\PhpSign\Utilities\RestApiRequester
     */
    protected $requester;

    /**
     * The Docusign account id
     *
     * @var string
     */
    protected $accountId;

    /**
     * The Docusign API URL
     *
     * @var string
     */
    protected $endPoint;

    /**
     * The Docusign API Auth URL
     *
     * @var string
     */
    protected $authEndPoint;

    /**
     * The Signature URL
     * Docusign does not provide signature URL for signers.
     * Instead, it creates a "recipient view" which URL is time limited (cannot be sent by email).
     * Your application must define a route that will call the redirectSigner() function.
     *
     * @var string
     */
    protected $signatureUrl;

	/**
     * Create a new DocusignDriver instance.
     *
     * @return void
     */
    public function __construct(array $config)
    {
        $requiredConfigKeys = [
            'mode', 
            // An API key created from the Admin panel :
            'integrator_key', 
            // The user we want to impersonate in the docusign account
            'user_id', 
            // A private key associated to the Integrator key
            'private_key', 
            // A redirect URI associated to the Integrator key
            'redirect_uri',
            // The URL that will redirect the signers to Docusign signature page
            'signature_url',
        ];

        foreach($requiredConfigKeys as $key){

            if(!isset($config[$key]) || $config[$key] === ''){

                throw new ValidationException('Docusign config parameter "'.$key.'" must be set');
            }
        }

        if($config['mode'] === 'production'){

            $this->endPoint = 'https://www.docusign.net/restapi/v2';
            $this->authEndPoint = 'https://account.docusign.com';

        }else{

            $this->endPoint = 'https://demo.docusign.net/restapi/v2';
            $this->authEndPoint = 'https://account-d.docusign.com';
        }

        $this->signatureUrl = rtrim($config['signature_url'], '/');

        // ---------------------------------------------------------------
        // Create Access Token
        // ---------------------------------------------------------------
        $currentTime = time();
        $token = [
            // The integrator key (also known as client ID) of the application.
            "iss" => $config['integrator_key'],
            // The user ID of the user to be impersonated.
            "sub" => $config['user_id'],
            // The URI of the authentication service instance to be used. (only server name without https:// and uri)
            "aud" => substr($this->authEndPoint, 8),
            // The scopes to request. For the JWT bearer grant, the requested scope should be signature impersonation.
            "scope" => "signature impersonation",
            // The DateTime when the JWT was issued, in Unix epoch format.
            "nbf" => $currentTime,
            "iat" => $currentTime,
            // The DateTime when the JWT assertion will expire, in Unix epoch format. Defaults to one hour from the value of iat, and cannot be set to a greater value.
            "exp" => $currentTime + 60 * 1000,
        ];

        $requester = new RestApiRequester('', $this->authEndPoint);
        $apiResult = $requester->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 
            'assertion' => JWT::encode($token, $config['private_key'], 'RS256'),
        ], [], [
            'Accept' => 'application/json'
        ]);
        $data = json_decode($apiResult->getBody()->getContents(), true);


        // ---------------------------------------------------------------
        // Require consent
        // ---------------------------------------------------------------
        if(isset($data['error']))
        {
            if($data['error'] === 'consent_required')
            {
                $consentUrl = $this->authEndPoint.'/oauth/auth?'.http_build_query([
                    'response_type' => 'code',
                    'scope' => 'signature impersonation',
                    'client_id' => $config['integrator_key'],
                    'redirect_uri' => $config['redirect_uri'],
                ]);
                throw new \Exception('Ask for user consent : '.$consentUrl, 401);
            }
            throw new \Exception('Unexpected Docusign error requesting JWT token');
        }

        // ---------------------------------------------------------------
        // Set Access Token
        // ---------------------------------------------------------------
        $accessToken = $data['access_token'];
        $expireIn = $data['expires_in'];
        $requester->setApiKey($accessToken);
        
        // ---------------------------------------------------------------
        // Get User Info
        // ---------------------------------------------------------------
        $apiResult = $requester->get('/oauth/userinfo');
        $data = json_decode($apiResult->getBody()->getContents(), true);
        $this->accountId = $data['accounts'][0]['account_id'];

        $requester->setEndpoint($this->endPoint);
        $this->requester = $requester;
    }

    /**
     * Get the driver's name
     *
     * @return string
     */
    public function getName()
    {
        return 'docusign';
    }

    /**
     * Create a transaction from a scenario
     *
     * @param  \Helori\PhpSign\Elements\Scenario  $scenario
     * @return array
     */
    public function createTransaction(Scenario $scenario)
    {
        // -------------------------------------------
        // Signers
        // -------------------------------------------
        $signers = [];

    	foreach($scenario->getSigners() as $scSigner){

            // -------------------------------------------
            // A Signer is a specific Recipient type
            // -------------------------------------------
            $signer = [
                // ------------------------------------
                // Common recipient fields
                // ------------------------------------
                // Unique for the recipient. It is used by the tab element to indicate which recipient is to sign the Document.
                'recipientId' => $scSigner->getId(),
                // This element specifies the routing order of the recipient in the envelope.
                'routingOrder' => $scSigner->getId(),
                // Email of the recipient. Notification will be sent to this email id. Maximum Length: 100 characters.
                'email' => $scSigner->getEmail(),
                // Full legal name of the recipient. Maximum Length: 100 characters.
                'name' => $scSigner->getFullname(),


                // ------------------------------------
                // Disable email access code security
                // ------------------------------------
                // This optional element specifies the access code a recipient has to enter to validate the identity. Maximum Length: 50 characters.
                'accessCode' => '',
                // This optional attribute indicates that the access code is added to the email sent to the recipient; this nullifies the Security measure of Access Code on the recipient.
                'addAccessCodeToEmail' => false,

                // An optional array of strings that allows the sender to provide custom data about the recipient. 
                // This information is returned in the envelope but otherwise not used by DocuSign. 
                // String customField properties have a maximum length of 100 characters.
                'customFields' => [
                    $scSigner->getBirthday() ? $scSigner->getBirthday()->format('Y-m-d') : '',
                ],

                //'idCheckConfigurationName' => 'SMS',
                // Optional element. Contains the element: senderProvidedNumbers: 
                // Array that contains a list of phone numbers the recipient can use for SMS text authentication.
                /*'smsAuthentication' => [
                    'senderProvidedNumbers' => [
                        $scSigner->getPhone(),
                    ]
                ],
                'phoneAuthentication' => [
                    // When set to true then recipient can use whatever phone number they choose to.
                    'recipMayProvideNumber' => true,
                    // A list of phone numbers the recipient can use.
                    'senderProvidedNumbers' => [
                        $scSigner->getPhone(),
                    ]
                ],*/


                // Core properties about email notification
                // An optional complex type that has information for setting the language for the recipient's email information. 
                // It is composed of three elements
                'emailNotification' => [
                    // a string with the email message sent to the recipient. Maximum Length: 10000 characters.
                    'emailBody' => '',
                    // a string with the subject of the email sent to the recipient. Maximum Length: 100 characters.
                    'emailSubject' => '',
                    // The simple type enumeration of the language used
                    'supportedLanguage' => $scenario->getLang(),
                ],


                // Set the recipient as "embedded" (instead of "remote") by setting a clientUserId
                // When embedded, the recipient will not receive an email, except if EmbeddedRecipientStartUrl is specified
                // Not specifying the clientUserId leaves the signer as "remote" : an email will be sent by docusign.
                'clientUserId' => $scSigner->getId(),
                
                // If a clientUserId is set (= recipient is embedded) an email can still be sent by docusign with a link to your app.
                // The app is then responsible for authenticating the signer,
                // and must generate a signingView URL to redirect the signer.
                // Setting the magic value 'SIGN_AT_DOCUSIGN' causes the recipient to be both embedded,
                // and receive an official "please sign" email from DocuSign.
                'embeddedRecipientStartURL' => 'SIGN_AT_DOCUSIGN',

                // ------------------------------------
                // Signers Recipient
                // ------------------------------------
                // When set to true and the feature is enabled in the sender's account, the signing recipient is required to draw signatures and initials at each signature/initial tab (instead of adopting a signature/initial style or only drawing a signature/initial once).
                'signInEachLocation' => false,
                // Optional element. The email address for an InPersonSigner recipient Type. 
                // Maximum Length: 100 characters.
                'signerEmail' => $scSigner->getEmail(),
                // The full legal name of a signer for the envelope.
                // Required element with recipient type InPersonSigner. Maximum Length: 100 characters.
                'signerName' => $scSigner->getFullname(),
                // Specifies the Tabs associated with the recipient.
                // Optional element only used with recipient types "InPersonSigner" and "Signers".
                'tabs' => [
                    'signHereTabs' => [],
                ]
            ];

            // -------------------------------------------
            // Signatures locations on documents
            // -------------------------------------------
            $signHereTabs = [];
            foreach($scenario->getSignatures() as $scSignature){
            	if($scSignature->getSignerId() === $scSigner->getId()){
                    $signHereTabs[] = [
                        'xPosition' => $scSignature->getX(),
                        'yPosition' => $scSignature->getY(),
                        'pageNumber' => $scSignature->getPage(),
                        'recipientId' => $scSignature->getSignerId(),
                        'documentId' => $scSignature->getDocumentId(),
                        'tabLabel' => $scSignature->getLabel(),
                    ];
            	}
            }
            $signer['tabs']['signHereTabs'] = $signHereTabs;

            $signers[] = $signer;
    	}

        // -------------------------------------------
        // Documents
        // -------------------------------------------
        $documents = [];

    	foreach($scenario->getDocuments() as $scDocument){

            $documents[] = [
                // A user-specified ID that identifies this document. You'll use this ID to associate a tab with a document.
                'documentId' => $scDocument->getId(),
                // The name of the document. This is the name that appears in the text of the email and when the recipient retrieves the document.
                'name' => $scDocument->getName(),
                // The contents of the document encoded as base64.
                'documentBase64' => base64_encode(file_get_contents($scDocument->getFilepath())),
                // The file extension of the document file.
                'fileExtension' => 'PDF',
            ];
    	}

        // -------------------------------------------
        // Envelope
        // -------------------------------------------
        $envelope = [
            // The recipients who should get the envelope
            'recipients' => [
                'signers' => $signers,
            ],
            // The documents for the recipient to view or sign
            'documents' => $documents,
            
            // -----------------------------------
            // Send webhooks to track envelope status
            // -----------------------------------
            'eventNotification' => [
                'url' => $scenario->getStatusUrl(),
                'includeTimezone' => true,
            ],

            // -----------------------------------
            // Envelope email settings
            // This package does not use it.
            // -----------------------------------
            // The subject of the email used to send the envelope (can be overwritten for each recipient)
            'emailSubject' => 'Fake email subject',
            // The body of the email message.
            //'emailBlurb' => '',
            // Additional settings that let you control the reply-to address and BCC addresses.
            //'emailSettings' => [],

            // When true, users can define the routing order of recipients while sending documents for signature.
            'change_routing_order' => false,

            // -----------------------------------
            // Envelope definition
            // -----------------------------------
            // Create envelope and set envelope status to "sent" to immediately send the signature request
            'status' => 'sent',
            // Custom fields
            'customFields' => [
                'textCustomFields' => [
                    [
                        'fieldId' => 'title',
                        'name' => 'title',
                        'value' => $scenario->getTitle(),
                    ],
                    [
                        'fieldId' => 'customId',
                        'name' => 'customId',
                        'value' => $scenario->getCustomId(),
                    ],
                ],
            ],

            // Expiration
            'expirations' => [
                // An integer that sets the number of days the envelope is active.
                'expireAfter' => $this->getExpirationDays(),
                // When set to true, the envelope expires (is no longer available for signing) in the set number of days. 
                // If false, the account default setting is used. If the account does not have an expiration setting, 
                // the DocuSign default value of 120 days is used.
                'expireEnabled' => true,
                // An integer that sets the number of days before envelope expiration that an expiration warning email is sent to the recipient. 
                // If set to 0 (zero), no warning email is sent.
                'expireWarn' => 0
            ],
        ];

        $apiResult = $this->requester->post('/accounts/'.$this->accountId.'/envelopes', $envelope);
        $data = json_decode($apiResult->getBody()->getContents(), true);

        $envelopeId = $data['envelopeId'];
        return $this->getTransaction($envelopeId);
    }

    /**
     * Get transaction
     *
     * @param  string  $transactionId
     * @return Transaction
     */
    public function getTransaction(string $transactionId)
    {
        $apiResult = $this->requester->get('/accounts/'.$this->accountId.'/envelopes/'.$transactionId);
        $envelope = json_decode($apiResult->getBody()->getContents(), true);

        $apiResult = $this->requester->get('/accounts/'.$this->accountId.'/envelopes/'.$transactionId.'/recipients');
        $recipients = json_decode($apiResult->getBody()->getContents(), true);

        $apiResult = $this->requester->get('/accounts/'.$this->accountId.'/envelopes/'.$transactionId.'/custom_fields');
        $customFields = json_decode($apiResult->getBody()->getContents(), true);

        $apiResult = $this->requester->get('/accounts/'.$this->accountId.'/envelopes/'.$transactionId.'/documents');
        $documents = json_decode($apiResult->getBody()->getContents(), true);

        //dd($recipients);
        dd($envelope, $recipients, $customFields, $documents);
        $signers = [];

        foreach($recipients['signers'] as $docuSigner){

            $signer = new SignerResult();
            $signer->setId($docuSigner['recipientId']);
            $signer->setFullname($docuSigner['name']);
            $signer->setEmail($docuSigner['email']);
            //$signer->setPhone($docuSigner['smsAuthentication']['senderProvidedNumbers'][0]);
            $signer->setStatus($this->convertSignerStatus($docuSigner['status']));
            //$signer->setUrl($signingView['']);
            //$signer->setActionAt($signerInfo->structMem('actionDate')->scalarVal());
            //$signer->setError($signerInfo->structMem('error')->scalarVal());

            $url = 'https://demo.docusign.net/Member/EmailStart.aspx?'.http_build_query([
                // Security code
                'a' => '',
                // ?
                'acct' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                // Recipient id Guid
                'er' => $docuSigner['recipientIdGuid'],
                // Envelope Id
                'espei' => $transactionId,
            ]);

            //https://demo.docusign.net/Member/EmailStart.aspx?a=b016ccb4-a910-41bb-adbd-e73fc57b84c7&er=aa054431-b7c8-4554-96ae-5bc528d3577d&espei=ea3f9f97-5eed-43d1-80a4-4f22e8348cf8
            //https://demo.docusign.net/Member/EmailStart.aspx?a=b016ccb4-a910-41bb-adbd-e73fc57b84c7&er=aa054431-b7c8-4554-96ae-5bc528d3577d&espei=ea3f9f97-5eed-43d1-80a4-4f22e8348cf8

            $signer->setUrl($url);

            /*https://demo.docusign.net/Member/EmailStart.aspx?
            a=82aa767f-1447-407c-ad66-e297f49b16e7& //
            acct=c9b18526-effe-417f-8165-033f44ab0af7&
            er=0e8d6c91-f3e9-4b5e-a59b-ad060f5832dd&
            espei=1f185fa7-e81d-45f5-b1ea-15a6160c5448*/

            /*https://demo.docusign.net/Member/EmailStart.aspx?
                a=28c42209-555f-49f4-97b1-9cf375921c7a&
                acct=c9b18526-effe-417f-8165-033f44ab0af7&
                er=b000ff18-38b3-4a66-a431-c21fed21839e&
                espei=a90da8e2-0a4d-4de5-be3b-4987f0592b3f*/

            if(isset($docuSigner['customFields']) && count($docuSigner['customFields']) > 0){
                $signer->setBirthday(DateParser::parse($docuSigner['customFields'][0]));
            }

            $signers[] = $signer;

            // The signer URL has very short lifetime
            // It can be re-generated as much as needed
            // Use it quickly after retreiving it (making a redirect)
            $apiResult = $this->requester->post('/accounts/'.$this->accountId.'/envelopes/'.$transactionId.'/views/recipient', [

            ]);
            $recipientView = json_decode($apiResult->getBody()->getContents(), true);

            /*$recipientViewRequest = new RecipientViewRequest();
            $recipientViewRequest->setReturnUrl('https://algoart.fr/return');
            $recipientViewRequest->setAuthenticationMethod("email");
            $recipientViewRequest->setUserName($docuSigner->getName());
            $recipientViewRequest->setEmail($docuSigner->getEmail());
            $recipientViewRequest->setRecipientId($docuSigner->getRecipientId());
            $recipientViewRequest->setClientUserId($docuSigner->getClientUserId());

            $signingView = $envelopeApi->createRecipientView(
                $this->accountId, 
                $envelope->getEnvelopeId(), 
                $recipientViewRequest);*/
        }

        $transaction = new Transaction($this->getName());
        $transaction->setId($envelope['envelopeId']);
        $transaction->setStatus($this->convertTransactionStatus($envelope['status']));
        $transaction->setSigners($signers);
        $createdAt = DateParser::parse($envelope['createdDateTime']);
        $transaction->setCreatedAt($createdAt);
        $transaction->setExpireAt($createdAt->copy()->addDays($this->getExpirationDays()));

        if(isset($customFields['textCustomFields'])){
            foreach($customFields['textCustomFields'] as $customField){
                if($customField['name'] === 'title'){
                    $transaction->setTitle($customField['value']);
                }else if($customField['name'] === 'customId'){
                    $transaction->setCustomId($customField['value']);
                }
            }
        }

        dd($transaction);

        return $transaction;
    }

    /**
     * Get transaction documents
     *
     * @param  string  $transactionId
     * @return array
     */
    public function getDocuments(string $transactionId)
    {
        $files = [];

        $envelopeApi = new EnvelopesApi($this->client);

        $docsList = $envelopeApi->listDocuments($this->accountId, $transactionId);
        $documents = $docsList->getEnvelopeDocuments();

        foreach($documents as $document)
        {
            // The signature certificate is one of the returned documents
            $isCertificate = (strpos($document->getDocumentId(), 'certificate') !== false);
            if($isCertificate){
                continue;
            }

            $content = $envelopeApi->getDocument($this->accountId, $transactionId, $document->getDocumentId());

            $files[] = [
                'name' => $document->getName(),
                'content' => $content,

                // Universign specific :
                'attachment_tab_id' => $document->getAttachmentTabId(),
                'available_document_types' => $document->getAvailableDocumentTypes(),
                'contains_pdf_form_fields' => $document->getContainsPdfFormFields(),
                'display' => $document->getDisplay(),
                'document_fields' => $document->getDocumentFields(),
                'document_group' => $document->getDocumentGroup(),
                'document_id' => $document->getDocumentId(),
                'error_details' => $document->getErrorDetails(),
                'include_in_download' => $document->getIncludeInDownload(),
                'order' => $document->getOrder(),
                'pages' => $document->getPages(),
                'signer_must_acknowledge' => $document->getSignerMustAcknowledge(),
                'template_locked' => $document->getTemplateLocked(),
                'template_required' => $document->getTemplateRequired(),
                'type' => $document->getType(),
                'uri' => $document->getUri(),
            ];
        }

        return $files;
    }

    /**
     * Convert Docusign transaction status to PhpSign transaction status
     *
     * @param  string  $docusignStatus
     * @return string
     */
    protected function convertTransactionStatus(string $docusignStatus)
    {
        $status = Transaction::STATUS_UNKNOWN;

        switch ($docusignStatus) {

            // Envelope is a draft
            case 'created':
                $status = Transaction::STATUS_DRAFT;
                break;

            // Ready to sign
            case 'sent':
                $status = Transaction::STATUS_READY;
                break;

            // Recipient opened a document, or recipient agreed to signature.
            case 'delivered':
                $status = Transaction::STATUS_READY;
                break;

            // Recipient signed
            case 'completed':
                $status = Transaction::STATUS_COMPLETED;
                break;

            // Recipient declined to sign.
            case 'declined':
                $status = Transaction::STATUS_REFUSED;
                break;

            // Sender voided envelope.
            case 'voided':
                $status = Transaction::STATUS_CANCELED;
                break;

            /*case 'processing':
                $status = Transaction::STATUS_READY;
                break;

            case 'signed':
                $status = Transaction::STATUS_COMPLETED;
                break;

            case 'deleted':
                $status = Transaction::STATUS_CANCELED;
                break;

            case 'timedout':
                $status = Transaction::STATUS_EXPIRED;
                break;*/
            
            default:
                $status = Transaction::STATUS_UNKNOWN;
                break;
        }
        return $status;
    }

    /**
     * Convert Docusign signer status to PhpSign signer status
     *
     * @param  string  $docusignStatus
     * @return string
     */
    protected function convertSignerStatus(string $docusignStatus)
    {
        $status = SignerResult::STATUS_UNKNOWN;

        switch ($docusignStatus) {

            // The recipient is in a draft state. This is only associated with draft envelopes (envelopes with a created status).
            case 'created':
                $status = SignerResult::STATUS_WAITING;
                break;

            // The recipient has been sent an email notification that it is their turn to sign and envelope.
            case 'sent':
                $status = SignerResult::STATUS_READY;
                break;

            // The recipient has viewed the documents in an envelope through the DocuSign signing web site. This is not an email delivery of the documents in an envelope.
            case 'delivered':
                $status = SignerResult::STATUS_ACCESSED;
                break;

            // The recipient has completed (signed) all required tags in an envelope. This is a temporary state during processing, after which the recipient is automatically moved to completed.
            case 'signed':
                $status = SignerResult::STATUS_SIGNED;
                break;

            // The recipient declined to sign the documents in the envelope.
            case 'declined':
                $status = SignerResult::STATUS_CANCELED;
                break;

            // The recipient has completed their actions (signing or other required actions if not a signer) for an envelope.
            case 'completed':
                $status = SignerResult::STATUS_SIGNED;
                break;

            // The recipient has finished signing and the system is waiting a fax attachment by the recipient before completing their signing step.
            /*case 'faxpending':
                $status = SignerResult::STATUS_SIGNED;
                break;

            // The recipient's email system auto-responded to the email from DocuSign. This status is used in the web console to inform senders about the bounced email. This status is used only if "send-on-behalf-of" is turned off for the account.
            case 'autoresponded':
                $status = SignerResult::STATUS_READY;
                break;*/

            default:
                $status = SignerResult::STATUS_UNKNOWN;
                break;
        }
        return $status;
    }

    /**
     * Cancel a transaction
     *
     * @param  string  $transactionId
     * @return \Helori\PhpSign\Elements\Transaction
     */
    public function cancelTransaction(string $transactionId)
    {
        throw new SignException('cancelTransaction is not implemented yet for Docusign');
    }

    /**
     * Get the driver's specific expiration days
     *
     * @return int
     */
    public function getExpirationDays()
    {
        return 14;
    }

    /**
     * Convert a webhook request into the common webhook data format
     *
     * @param  array  $requestData
     * @return \Helori\PhpSign\Elements\Webhook
     */
    public function formatWebhook(array $requestData)
    {
        throw new SignException('formatWebhook is not implemented yet for Docusign');
    }
}
