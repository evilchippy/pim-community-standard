<?php

namespace Webkul\ShopifyBundle\Entity;

/**
 * DataMapping
 */
class DataMapping
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $entityType;

    /**
     * @var string
     */
    private $code;

    /**
     * @var string
     */
    private $externalId;

    /**
     * @var string
     */
    private $relatedId;

    /**
     * @var integer
     */
    private $jobInstanceId;

    /**
     * @var string
     */
    private $apiUrl;


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set entityType
     *
     * @param string $entityType
     *
     * @return DataMapping
     */
    public function setEntityType($entityType)
    {
        $this->entityType = $entityType;

        return $this;
    }

    /**
     * Get entityType
     *
     * @return string
     */
    public function getEntityType()
    {
        return $this->entityType;
    }

    /**
     * Set code
     *
     * @param string $code
     *
     * @return DataMapping
     */
    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * Get code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Set externalId
     *
     * @param string $externalId
     *
     * @return DataMapping
     */
    public function setExternalId($externalId)
    {
        $this->externalId = $externalId;

        return $this;
    }

    /**
     * Get externalId
     *
     * @return string
     */
    public function getExternalId()
    {
        return $this->externalId;
    }

    /**
     * Set relatedId
     *
     * @param string $relatedId
     *
     * @return DataMapping
     */
    public function setRelatedId($relatedId)
    {
        $this->relatedId = $relatedId;

        return $this;
    }

    /**
     * Get relatedId
     *
     * @return string
     */
    public function getRelatedId()
    {
        return $this->relatedId;
    }

    /**
     * Set jobInstanceId
     *
     * @param integer $jobInstanceId
     *
     * @return DataMapping
     */
    public function setJobInstanceId($jobInstanceId)
    {
        $this->jobInstanceId = $jobInstanceId;

        return $this;
    }

    /**
     * Get jobInstanceId
     *
     * @return integer
     */
    public function getJobInstanceId()
    {
        return $this->jobInstanceId;
    }

    /**
     * Set apiUrl
     *
     * @param string $apiUrl
     *
     * @return DataMapping
     */
    public function setApiUrl($apiUrl)
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }

    /**
     * Get apiUrl
     *
     * @return string
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }
    /**
     * @var array
     */
    private $relatedSource;


    /**
     * Set relatedSource
     *
     * @param array $relatedSource
     *
     * @return DataMapping
     */
    public function setRelatedSource($relatedSource)
    {
        $this->relatedSource = $relatedSource;

        return $this;
    }

    /**
     * Get relatedSource
     *
     * @return array
     */
    public function getRelatedSource()
    {
        return $this->relatedSource;
    }
}
