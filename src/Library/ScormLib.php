<?php

namespace Peopleaps\Scorm\Library;

use DOMDocument;
use Peopleaps\Scorm\Entity\Sco;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Illuminate\Support\Str;

class ScormLib
{
    // SCORM namespace constants
    const SCORM_12_NAMESPACE = null; // SCORM 1.2 doesn't use namespaces
    const ADLCP_V1P2_NAMESPACE = 'http://www.adlnet.org/xsd/adlcp_rootv1p2';
    const ADLCP_V1P3_NAMESPACE = 'http://www.adlnet.org/xsd/adlcp_v1p3';
    const IMSSS_NAMESPACE = 'http://www.imsglobal.org/xsd/imsss';
    const ADLSEQ_NAMESPACE = 'http://www.adlnet.org/xsd/adlseq_v1p3';
    const ADLNAV_NAMESPACE = 'http://www.adlnet.org/xsd/adlnav_v1p3';

    private $scormVersion = null;
    private $namespaces = [];

    /**
     * Looks for the organization to use.
     *
     * @return array of Sco
     *
     * @throws InvalidScormArchiveException If a default organization
     *                                      is defined and not found
     */
    public function parseOrganizationsNode(DOMDocument $dom)
    {
        // Detect SCORM version and namespaces first
        $this->detectScormVersion($dom);
        $this->extractNamespaces($dom);

        $organizationsList = $dom->getElementsByTagName('organizations');
        $resources = $dom->getElementsByTagName('resource');

        \Log::info('parseOrganizationsNode: Found ' . $organizationsList->length . ' organizations, ' . $resources->length . ' resources, SCORM version: ' . $this->scormVersion);

        if ($organizationsList->length > 0) {
            $organizations = $organizationsList->item(0);
            $organization = $organizations->firstChild;

            if (
                !is_null($organizations->attributes)
                && !is_null($organizations->attributes->getNamedItem('default'))
            ) {
                $defaultOrganization = $organizations->attributes->getNamedItem('default')->nodeValue;
            } else {
                $defaultOrganization = null;
            }

            // No default organization is defined
            if (is_null($defaultOrganization)) {
                while (
                    !is_null($organization)
                    && 'organization' !== $organization->nodeName
                ) {
                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {
                    \Log::info('parseOrganizationsNode: No organization found, using enhanced resource parsing');
                    return $this->parseResourceNodesWithStructure($resources);
                }
            }
            // A default organization is defined
            else {
                while (
                    !is_null($organization)
                    && ('organization' !== $organization->nodeName
                        || is_null($organization->attributes->getNamedItem('identifier'))
                        || $organization->attributes->getNamedItem('identifier')->nodeValue !== $defaultOrganization)
                ) {
                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {
                    \Log::error('parseOrganizationsNode: Default organization not found: ' . $defaultOrganization);
                    throw new InvalidScormArchiveException('default_organization_not_found_message');
                }
            }

            $parsedItems = $this->parseItemNodes($organization, $resources);

            // ENHANCEMENT: Handle single SCO with multiple resources scenario
            if (count($parsedItems) === 1 && $this->countScoResources($resources) > 1) {
                \Log::info('parseOrganizationsNode: Single item found but multiple SCO resources detected, enhancing structure');
                return $this->enhanceWithAdditionalResources($parsedItems, $resources);
            }

            // Handle empty organization with resources
            if (empty($parsedItems) && $this->countScoResources($resources) > 0) {
                \Log::info('parseOrganizationsNode: Empty organization but SCO resources found, creating structure from resources');
                return $this->parseResourceNodesWithStructure($resources);
            }

            return $parsedItems;
        } else {
            \Log::error('parseOrganizationsNode: No organizations found in manifest');
            throw new InvalidScormArchiveException('no_organization_found_message');
        }
    }

    /**
     * Detect SCORM version from manifest
     */
    private function detectScormVersion(DOMDocument $dom)
    {
        $manifestNode = $dom->documentElement;

        // Check schema location or version attributes
        $schemaLocation = $manifestNode->getAttribute('xsi:schemaLocation');
        $version = $manifestNode->getAttribute('version');

        if (strpos($schemaLocation, '2004') !== false || strpos($schemaLocation, 'v1p3') !== false) {
            if (strpos($schemaLocation, '4th') !== false || strpos($schemaLocation, 'CAM_v1p1') !== false) {
                $this->scormVersion = '2004_4th';
            } else if (strpos($schemaLocation, '3rd') !== false) {
                $this->scormVersion = '2004_3rd';
            } else {
                $this->scormVersion = '2004_2nd';
            }
        } else if (strpos($schemaLocation, '1.2') !== false || strpos($schemaLocation, 'v1p2') !== false) {
            $this->scormVersion = '1.2';
        } else if ($version === '1.2') {
            $this->scormVersion = '1.2';
        } else {
            // Default assumption based on common patterns
            $adlcpNodes = $dom->getElementsByTagNameNS(self::ADLCP_V1P3_NAMESPACE, '*');
            $this->scormVersion = $adlcpNodes->length > 0 ? '2004' : '1.2';
        }

        \Log::info('detectScormVersion: Detected SCORM version: ' . $this->scormVersion);
    }

    /**
     * Extract namespaces from manifest
     */
    private function extractNamespaces(DOMDocument $dom)
    {
        $manifestNode = $dom->documentElement;
        $xpath = new \DOMXPath($dom);

        // Get all namespace declarations
        try {
            $namespaceNodes = $xpath->query('namespace::*', $manifestNode);
            if ($namespaceNodes) {
                foreach ($namespaceNodes as $node) {
                    $this->namespaces[$node->localName] = $node->nodeValue;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('extractNamespaces: Error extracting namespaces - ' . $e->getMessage());
        }

        \Log::info('extractNamespaces: Found namespaces: ' . json_encode($this->namespaces));
    }

    /**
     * Creates defined structure of SCOs.
     *
     * @return array of Sco
     *
     * @throws InvalidScormArchiveException
     */
    private function parseItemNodes(\DOMNode $source, \DOMNodeList $resources, Sco $parentSco = null)
    {
        $item = $source->firstChild;
        $scos = [];
        $itemCount = 0;

        while (!is_null($item)) {
            if ('item' === $item->nodeName) {
                $itemCount++;

                $sco = new Sco();
                $scos[] = $sco;
                $sco->setUuid(Str::uuid());
                $sco->setScoParent($parentSco);

                $this->findAttrParams($sco, $item, $resources);
                $this->findNodeParams($sco, $item->firstChild);

                // Parse SCORM 2004 sequencing information
                if ($this->isScorm2004()) {
                    $this->parseSequencingInfo($sco, $item);
                }

                if ($sco->isBlock()) {
                    $children = $this->parseItemNodes($item, $resources, $sco);
                    $sco->setScoChildren($children);
                } else {
                    // Check for sub-resources that might belong to this SCO
                    $this->attachSubResources($sco, $resources);
                }
            }
            $item = $item->nextSibling;
        }

        \Log::info("parseItemNodes: Found {$itemCount} items, returning " . count($scos) . " SCOs");
        return $scos;
    }

    /**
     * Enhanced resource parsing that attempts to create logical structure
     */
    private function parseResourceNodesWithStructure(\DOMNodeList $resources)
    {
        $scos = [];
        $scoResources = [];
        $assetResources = [];

        \Log::info('parseResourceNodesWithStructure: Parsing ' . $resources->length . ' resources for SCORM ' . $this->scormVersion);

        // First, categorize resources
        foreach ($resources as $resource) {
            if (!is_null($resource->attributes)) {
                $resourceType = $this->getResourceType($resource);
                    $identifier = $resource->attributes->getNamedItem('identifier');
                    $href = $resource->attributes->getNamedItem('href');

                \Log::debug('parseResourceNodesWithStructure: Resource', [
                    'identifier' => $identifier ? $identifier->nodeValue : 'null',
                    'href' => $href ? $href->nodeValue : 'null',
                    'type' => $resourceType
                ]);

                if ($resourceType === 'sco' && !is_null($identifier) && !is_null($href)) {
                    $scoResources[] = $resource;
                } elseif (!is_null($href)) {
                    $assetResources[] = $resource;
                }
            }
        }

        // Create SCOs from SCO resources
        foreach ($scoResources as $resource) {
            $sco = $this->createScoFromResource($resource);
            if ($sco) {
                $scos[] = $sco;
            }
        }

        // If we have multiple SCOs, try to establish relationships
        if (count($scos) > 1) {
            $scos = $this->establishScoRelationships($scos, $assetResources);
        }

        \Log::info('parseResourceNodesWithStructure: Found ' . count($scos) . ' SCOs');
        return $scos;
    }

    /**
     * Check if current SCORM version is 2004 family
     */
    private function isScorm2004()
    {
        return strpos($this->scormVersion, '2004') !== false;
    }

    /**
     * Parse SCORM 2004 sequencing information
     */
    private function parseSequencingInfo(Sco $sco, \DOMNode $item)
    {
        // Look for imsss:sequencing nodes
        $sequencingNodes = [];
        $child = $item->firstChild;

        while (!is_null($child)) {
            if ($child->nodeName === 'imsss:sequencing' || $child->nodeName === 'sequencing') {
                $sequencingNodes[] = $child;
            }
            $child = $child->nextSibling;
        }

        foreach ($sequencingNodes as $sequencingNode) {
            $this->parseSequencingNode($sco, $sequencingNode);
        }
    }

    /**
     * Parse individual sequencing node
     */
    private function parseSequencingNode(Sco $sco, \DOMNode $sequencingNode)
    {
        $child = $sequencingNode->firstChild;

        while (!is_null($child)) {
            switch ($child->nodeName) {
                case 'imsss:controlMode':
                case 'controlMode':
                    $this->parseControlMode($sco, $child);
                    break;
                case 'imsss:sequencingRules':
                case 'sequencingRules':
                    $this->parseSequencingRules($sco, $child);
                    break;
                case 'imsss:deliveryControls':
                case 'deliveryControls':
                    $this->parseDeliveryControls($sco, $child);
                    break;
                case 'imsss:objectives':
                case 'objectives':
                    $this->parseObjectives($sco, $child);
                    break;
            }
            $child = $child->nextSibling;
        }
    }

    /**
     * Parse control mode settings
     */
    private function parseControlMode(Sco $sco, \DOMNode $controlMode)
    {
        $attributes = $controlMode->attributes;
        if ($attributes) {
            $choice = $attributes->getNamedItem('choice');
            $flow = $attributes->getNamedItem('flow');

            // Store sequencing information (you may need to add these methods to Sco entity)
            if (method_exists($sco, 'setChoiceEnabled') && $choice) {
                $sco->setChoiceEnabled($choice->nodeValue === 'true');
            }
            if (method_exists($sco, 'setFlowEnabled') && $flow) {
                $sco->setFlowEnabled($flow->nodeValue === 'true');
            }
        }
    }

    /**
     * Parse sequencing rules
     */
    private function parseSequencingRules(Sco $sco, \DOMNode $sequencingRules)
    {
        // Implementation depends on your Sco entity capabilities
        // This is where you'd parse pre/post conditions, exit/retry rules, etc.
    }

    /**
     * Parse delivery controls
     */
    private function parseDeliveryControls(Sco $sco, \DOMNode $deliveryControls)
    {
        $attributes = $deliveryControls->attributes;
        if ($attributes) {
            $tracked = $attributes->getNamedItem('tracked');
            $completionSetByContent = $attributes->getNamedItem('completionSetByContent');

            if (method_exists($sco, 'setTracked') && $tracked) {
                $sco->setTracked($tracked->nodeValue === 'true');
            }
            if (method_exists($sco, 'setCompletionSetByContent') && $completionSetByContent) {
                $sco->setCompletionSetByContent($completionSetByContent->nodeValue === 'true');
            }
        }
    }

    /**
     * Parse objectives
     */
    private function parseObjectives(Sco $sco, \DOMNode $objectives)
    {
        // Parse primary and secondary objectives
        // Implementation depends on your requirements
    }

    /**
     * Count the number of SCO type resources
     */
    private function countScoResources(\DOMNodeList $resources)
    {
        $count = 0;
        foreach ($resources as $resource) {
            if ($this->getResourceType($resource) === 'sco') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get resource type with proper SCORM version handling
     */
    private function getResourceType(\DOMNode $resource)
    {
        $scormType = null;

        if ($this->scormVersion === '1.2') {
            // SCORM 1.2 uses adlcp:scormtype attribute without namespace
            $scormType = $resource->attributes->getNamedItem('scormtype');
            if (is_null($scormType)) {
                $scormType = $resource->attributes->getNamedItem('adlcp:scormtype');
            }
        } else {
            // SCORM 2004 uses adlcp:scormType with namespace
            $namespaces = [
                self::ADLCP_V1P3_NAMESPACE,
                self::ADLCP_V1P2_NAMESPACE
            ];

            foreach ($namespaces as $namespace) {
                $scormType = $resource->attributes->getNamedItemNS($namespace, 'scormType');
                if (!is_null($scormType)) {
                    break;
                }
            }

            // Fallback to non-namespaced attribute
            if (is_null($scormType)) {
                $scormType = $resource->attributes->getNamedItem('scormType');
                if (is_null($scormType)) {
                    $scormType = $resource->attributes->getNamedItem('adlcp:scormType');
                }
            }
        }

        $type = $scormType ? strtolower($scormType->nodeValue) : 'asset';

        // Handle different type variations
        switch ($type) {
            case 'sco':
                return 'sco';
            case 'asset':
                return 'asset';
            default:
                // Check if it has launchable content
                $href = $resource->attributes->getNamedItem('href');
                if ($href && $this->isLaunchableContent($href->nodeValue)) {
                    return 'sco';
                }
                return 'asset';
        }
    }

    /**
     * Check if content is launchable (HTML, etc.)
     */
    private function isLaunchableContent($href)
    {
        $extension = strtolower(pathinfo($href, PATHINFO_EXTENSION));
        $launchableExtensions = ['html', 'htm', 'xhtml', 'xml'];

        return in_array($extension, $launchableExtensions);
    }

    /**
     * Enhance existing SCOs with additional resources that might be related
     */
    private function enhanceWithAdditionalResources(array $scos, \DOMNodeList $resources)
    {
        if (empty($scos)) {
            return $scos;
        }

        $mainSco = $scos[0];
        $usedResourceIds = [];

        // Collect already used resource IDs
        foreach ($scos as $sco) {
            if ($sco->getEntryUrl()) {
                $resourceId = $this->findResourceIdByUrl($sco->getEntryUrl(), $resources);
                if ($resourceId) {
                    $usedResourceIds[] = $resourceId;
                }
            }
        }

        // Find additional SCO resources
        $additionalScos = [];
        foreach ($resources as $resource) {
            if ($this->getResourceType($resource) === 'sco') {
                $identifier = $resource->attributes->getNamedItem('identifier');
                if ($identifier && !in_array($identifier->nodeValue, $usedResourceIds)) {
                    $additionalSco = $this->createScoFromResource($resource);
                    if ($additionalSco) {
                        $additionalSco->setScoParent($mainSco);
                        $additionalScos[] = $additionalSco;
                    }
                }
            }
        }

        if (!empty($additionalScos)) {
            if ($mainSco->isBlock()) {
                $existingChildren = $mainSco->getScoChildren() ?: [];
                $mainSco->setScoChildren(array_merge($existingChildren, $additionalScos));
            } else {
                // Convert main SCO to a block and add children
                $mainSco->setBlock(true);
                $mainSco->setScoChildren($additionalScos);
                $mainSco->setEntryUrl(null); // Blocks don't have entry URLs
            }
        }

        return $scos;
    }

    /**
     * Attach related sub-resources to a SCO
     */
    private function attachSubResources(Sco $sco, \DOMNodeList $resources)
    {
        if ($sco->isBlock()) {
            return; // Blocks don't have direct resources
        }

        $scoIdentifier = $sco->getIdentifier();
        $relatedResources = [];

        foreach ($resources as $resource) {
            $resourceType = $this->getResourceType($resource);
            if ($resourceType === 'sco') {
                $identifier = $resource->attributes->getNamedItem('identifier');
                $href = $resource->attributes->getNamedItem('href');

                if ($identifier && $href && $identifier->nodeValue !== $scoIdentifier) {
                    // Look for resources that might be related to this SCO
                    if ($this->areResourcesRelated($scoIdentifier, $identifier->nodeValue)) {
                        $childSco = $this->createScoFromResource($resource);
                        if ($childSco) {
                            $childSco->setScoParent($sco);
                            $relatedResources[] = $childSco;
                        }
                    }
                }
            }
        }

        if (!empty($relatedResources)) {
            $existingChildren = $sco->getScoChildren() ?: [];
            $sco->setScoChildren(array_merge($existingChildren, $relatedResources));
        }
    }

    /**
     * Check if two resources are related
     */
    private function areResourcesRelated($identifier1, $identifier2)
    {
        // Implement heuristics to determine if resources are related
        $id1Lower = strtolower($identifier1);
        $id2Lower = strtolower($identifier2);

        // Check for common patterns
        $patterns = [
            // One contains the other
            strpos($id1Lower, $id2Lower) !== false,
            strpos($id2Lower, $id1Lower) !== false,
            // Common prefixes (first 3+ characters)
            strlen($identifier1) > 3 && strlen($identifier2) > 3 &&
                substr($id1Lower, 0, 3) === substr($id2Lower, 0, 3),
            // Sequential numbering
            preg_match('/\d+$/', $identifier1) && preg_match('/\d+$/', $identifier2) &&
                preg_replace('/\d+$/', '', $id1Lower) === preg_replace('/\d+$/', '', $id2Lower)
        ];

        return in_array(true, $patterns, true);
    }

    /**
     * Create a SCO from a resource node
     */
    private function createScoFromResource(\DOMNode $resource)
    {
        $identifier = $resource->attributes->getNamedItem('identifier');
        $href = $resource->attributes->getNamedItem('href');

        if (is_null($identifier)) {
            \Log::error("createScoFromResource: SCO has no identifier");
            return null;
        }
        if (is_null($href)) {
            \Log::error("createScoFromResource: SCO has no href");
            return null;
        }

                    $sco = new Sco();
                    $sco->setUuid(Str::uuid());
                    $sco->setBlock(false);
                    $sco->setVisible(true);
                    $sco->setIdentifier($identifier->nodeValue);
                    $sco->setEntryUrl($href->nodeValue);

        // Extract additional parameters
        $this->extractResourceParameters($sco, $resource);

        // Try to get title from resource metadata
        $title = $this->extractTitleFromResource($resource);
        $sco->setTitle($title ?: $identifier->nodeValue);

        \Log::info("createScoFromResource: Created SCO - " . $sco->getIdentifier());
        return $sco;
    }

    /**
     * Extract parameters from resource node
     */
    private function extractResourceParameters(Sco $sco, \DOMNode $resource)
    {
        $attributes = $resource->attributes;
        if (!$attributes) {
            return;
        }

        // Extract parameters attribute
        $parameters = $attributes->getNamedItem('parameters');
        if ($parameters) {
            $sco->setParameters($parameters->nodeValue);
        }

        // Extract other resource-level metadata
        $child = $resource->firstChild;
        while (!is_null($child)) {
            if ($child->nodeName === 'file') {
                // Process file dependencies if needed
            } elseif ($child->nodeName === 'metadata') {
                $this->parseResourceMetadata($sco, $child);
            }
            $child = $child->nextSibling;
        }
    }

    /**
     * Parse resource metadata
     */
    private function parseResourceMetadata(Sco $sco, \DOMNode $metadata)
    {
        // Parse LOM metadata or other metadata formats
        // Implementation depends on your metadata requirements
    }

    /**
     * Extract title from resource
     */
    private function extractTitleFromResource(\DOMNode $resource)
    {
        // Look in metadata
        $metadataNodes = [];
        $child = $resource->firstChild;

        while (!is_null($child)) {
            if ($child->nodeName === 'metadata') {
                $metadataNodes[] = $child;
            }
            $child = $child->nextSibling;
        }

        foreach ($metadataNodes as $metadata) {
            $title = $this->findTitleInMetadata($metadata);
            if ($title) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Find title in metadata node
     */
    private function findTitleInMetadata(\DOMNode $metadata)
    {
        $xpath = new \DOMXPath($metadata->ownerDocument);
        
        // Register namespaces safely
        try {
            $xpath->registerNamespace('lom', 'http://ltsc.ieee.org/xsd/LOM');
            $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        } catch (\Exception $e) {
            \Log::warning('findTitleInMetadata: Error registering namespaces - ' . $e->getMessage());
        }

        // Try different title paths
        $titlePaths = [
            './/lom:title/lom:langstring',
            './/title/langstring',
            './/title',
            './/dc:title'
        ];

        foreach ($titlePaths as $path) {
            try {
                $titles = $xpath->query($path, $metadata);
                if ($titles && $titles->length > 0) {
                    return trim($titles->item(0)->nodeValue);
                }
            } catch (\Exception $e) {
                \Log::warning('extractTitleFromMetadata: Error executing XPath query "' . $path . '" - ' . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Find resource ID by URL
     */
    private function findResourceIdByUrl($url, \DOMNodeList $resources)
    {
        foreach ($resources as $resource) {
            $href = $resource->attributes->getNamedItem('href');
            if ($href && $href->nodeValue === $url) {
                $identifier = $resource->attributes->getNamedItem('identifier');
                return $identifier ? $identifier->nodeValue : null;
            }
        }
        return null;
    }

    /**
     * Attempt to establish relationships between SCOs
     */
    private function establishScoRelationships(array $scos, array $assetResources)
    {
        if (count($scos) <= 1) {
            return $scos;
        }

        // Try to identify a logical parent-child structure
        $parentSco = $this->findParentSco($scos);

        if ($parentSco) {
            $childScos = array_filter($scos, function ($sco) use ($parentSco) {
                return $sco !== $parentSco;
            });

            if (!empty($childScos)) {
                $parentSco->setBlock(true);
                $parentSco->setEntryUrl(null); // Blocks don't have entry URLs

                foreach ($childScos as $childSco) {
                    $childSco->setScoParent($parentSco);
                }

                $parentSco->setScoChildren($childScos);
                return [$parentSco];
            }
        }

        // If no clear structure, return all as siblings
        return $scos;
    }

    /**
     * Find the most likely parent SCO
     */
    private function findParentSco(array $scos)
    {
        $scores = [];

        foreach ($scos as $sco) {
            $score = 0;
            $identifier = strtolower($sco->getIdentifier());
            $entryUrl = strtolower($sco->getEntryUrl() ?: '');

            // Score based on common parent indicators
            if (strpos($identifier, 'main') !== false) $score += 10;
            if (strpos($identifier, 'index') !== false) $score += 8;
            if (strpos($identifier, 'intro') !== false) $score += 6;
            if (strpos($identifier, 'menu') !== false) $score += 5;
            if (strpos($identifier, 'toc') !== false) $score += 5;
            if (strpos($entryUrl, 'index') !== false) $score += 4;
            if (strpos($entryUrl, 'main') !== false) $score += 4;

            // Lower scores for obviously child-like names
            if (strpos($identifier, 'lesson') !== false) $score -= 2;
            if (strpos($identifier, 'page') !== false) $score -= 2;
            if (preg_match('/\d+/', $identifier)) $score -= 1;

            $scores[$sco->getIdentifier()] = $score;
        }

        // Return SCO with highest score
        $maxScore = max($scores);
        if ($maxScore > 0) {
            $parentId = array_search($maxScore, $scores);
            return array_filter($scos, function ($sco) use ($parentId) {
                return $sco->getIdentifier() === $parentId;
            })[0] ?? null;
        }

        return null;
    }

    /**
     * Initializes parameters of the SCO defined in attributes of the node.
     * It also look for the associated resource if it is a SCO and not a block.
     *
     * @throws InvalidScormArchiveException
     */
    private function findAttrParams(Sco $sco, \DOMNode $item, \DOMNodeList $resources)
    {
        $identifier = $item->attributes->getNamedItem('identifier');
        $isVisible = $item->attributes->getNamedItem('isvisible');
        $identifierRef = $item->attributes->getNamedItem('identifierref');
        $parameters = $item->attributes->getNamedItem('parameters');

        // throws an Exception if identifier is undefined
        if (is_null($identifier)) {
            throw new InvalidScormArchiveException('sco_with_no_identifier_message');
        }
        $sco->setIdentifier($identifier->nodeValue);

        // visible is true by default
        if (!is_null($isVisible) && 'false' === $isVisible) {
            $sco->setVisible(false);
        } else {
            $sco->setVisible(true);
        }

        // set parameters for SCO entry resource
        if (!is_null($parameters)) {
            $sco->setParameters($parameters->nodeValue);
        }

        // check if item is a block or a SCO. A block doesn't define identifierref
        if (is_null($identifierRef)) {
            $sco->setBlock(true);
        } else {
            $sco->setBlock(false);
            // retrieve entry URL
            $sco->setEntryUrl($this->findEntryUrl($identifierRef->nodeValue, $resources));
            
            // Check if this SCO has multiple HTML files (slides/modules)
            $htmlFiles = $this->findHtmlFilesInResource($identifierRef->nodeValue, $resources);
            if (count($htmlFiles) > 1) {
                \Log::info("findAttrParams: SCO " . $sco->getIdentifier() . " has " . count($htmlFiles) . " HTML files, creating child SCOs");
                $childScos = $this->createChildScosFromHtmlFiles($htmlFiles, $sco);
                $sco->setScoChildren($childScos);
            }
        }
    }

    /**
     * Initializes parameters of the SCO defined in children nodes.
     */
    private function findNodeParams(Sco $sco, \DOMNode $item)
    {
        while (!is_null($item)) {
            switch ($item->nodeName) {
                case 'title':
                    $sco->setTitle($item->nodeValue);
                    break;
                case 'adlcp:masteryscore':
                    $sco->setScoreToPassInt($item->nodeValue);
                    break;
                case 'adlcp:maxtimeallowed':
                case 'imsss:attemptAbsoluteDurationLimit':
                    $sco->setMaxTimeAllowed($item->nodeValue);
                    break;
                case 'adlcp:timelimitaction':
                case 'adlcp:timeLimitAction':
                    $action = strtolower($item->nodeValue);

                    if (
                        'exit,message' === $action
                        || 'exit,no message' === $action
                        || 'continue,message' === $action
                        || 'continue,no message' === $action
                    ) {
                        $sco->setTimeLimitAction($action);
                    }
                    break;
                case 'adlcp:datafromlms':
                case 'adlcp:dataFromLMS':
                    $sco->setLaunchData($item->nodeValue);
                    break;
                case 'adlcp:prerequisites':
                    $sco->setPrerequisites($item->nodeValue);
                    break;
                case 'imsss:minNormalizedMeasure':
                    $sco->setScoreToPassDecimal($item->nodeValue);
                    break;
                case 'adlcp:completionThreshold':
                    if ($item->nodeValue && !is_nan($item->nodeValue)) {
                        $sco->setCompletionThreshold(floatval($item->nodeValue));
                    }
                    break;
            }
            $item = $item->nextSibling;
        }
    }

    /**
     * Searches for the resource with the given id and retrieve URL to its content.
     *
     * @return string URL to the resource associated to the SCO
     *
     * @throws InvalidScormArchiveException
     */
    public function findEntryUrl($identifierref, \DOMNodeList $resources)
    {
        foreach ($resources as $resource) {
            $identifier = $resource->attributes->getNamedItem('identifier');

            if (!is_null($identifier)) {
                $identifierValue = $identifier->nodeValue;

                if ($identifierValue === $identifierref) {
                    $href = $resource->attributes->getNamedItem('href');

                    if (is_null($href)) {
                        throw new InvalidScormArchiveException('sco_resource_without_href_message');
                    }

                    return $href->nodeValue;
                }
            }
        }
        throw new InvalidScormArchiveException('sco_without_resource_message');
    }

    /**
     * Find HTML files in a resource
     *
     * @param string $identifierref
     * @param \DOMNodeList $resources
     * @return array
     */
    private function findHtmlFilesInResource($identifierref, \DOMNodeList $resources)
    {
        $htmlFiles = [];
        
        foreach ($resources as $resource) {
            $identifier = $resource->attributes->getNamedItem('identifier');
            
            if (!is_null($identifier) && $identifier->nodeValue === $identifierref) {
                // Find all file elements with .html extension
                $fileElements = $resource->getElementsByTagName('file');
                foreach ($fileElements as $file) {
                    $href = $file->attributes->getNamedItem('href');
                    if (!is_null($href) && preg_match('/\.html$/i', $href->nodeValue)) {
                        // Skip index.html as it's the main entry point
                        if (strtolower($href->nodeValue) !== 'index.html') {
                            $htmlFiles[] = $href->nodeValue;
                        }
                    }
                }
                break;
            }
        }
        
        return $htmlFiles;
    }

    /**
     * Create child SCOs from HTML files
     *
     * @param array $htmlFiles
     * @param Sco $parentSco
     * @return array
     */
    private function createChildScosFromHtmlFiles($htmlFiles, Sco $parentSco)
    {
        $childScos = [];
        
        foreach ($htmlFiles as $index => $htmlFile) {
            $childSco = new Sco();
            $childSco->setUuid(Str::uuid());
            $childSco->setScoParent($parentSco);
            $childSco->setBlock(false);
            $childSco->setVisible(true);
            
            // Create identifier based on parent and slide number
            $slideNumber = $index + 1;
            $childSco->setIdentifier($parentSco->getIdentifier() . '_' . $slideNumber);
            
            // Create title based on filename
            $title = $this->generateSlideTitle($htmlFile, $slideNumber);
            $childSco->setTitle($title);
            
            // Set entry URL to the HTML file
            $childSco->setEntryUrl($htmlFile);
            
            $childScos[] = $childSco;
            
            \Log::info("createChildScosFromHtmlFiles: Created child SCO - " . $childSco->getIdentifier() . " (" . $childSco->getTitle() . ")");
        }
        
        return $childScos;
    }

    /**
     * Generate a meaningful title for a slide based on filename
     *
     * @param string $htmlFile
     * @param int $slideNumber
     * @return string
     */
    private function generateSlideTitle($htmlFile, $slideNumber)
    {
        // Remove .html extension and convert to readable format
        $baseName = pathinfo($htmlFile, PATHINFO_FILENAME);
        
        // Convert underscores to spaces and capitalize words
        $title = str_replace('_', ' ', $baseName);
        $title = ucwords($title);
        
        // Handle special cases
        $title = str_replace('Html', 'HTML', $title);
        
        return "Slide {$slideNumber}: {$title}";
    }
}
