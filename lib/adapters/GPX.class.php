<?php
/*
 * Copyright (c) Patrick Hayes
 *
 * This code is open-source and licenced under the Modified BSD License.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
* PHP Geometry/GPX encoder/decoder
*
* This has been extended from the original geoPHP codebase to support
* reading and writing metadata tags including Garmin extensions for tracks, routes, and waypoints.
*
* gpxx: extensions are stored internally (and exported to GeoJSON) using gpxx_ so we can use dot 
* notation in javascript.
*/

class GPX extends GeoAdapter {
	private $namespace = FALSE;
	private $nss = ''; // Name-space string. eg 'georss:'

	// -------------------------------------------------

	/**
	* Read GPX string into geometry objects
	*
	* @param string $gpx A GPX string
	*
	* @return Geometry|GeometryCollection
	*/

	public function read($gpx) {
		return $this->geomFromText($gpx);
	}

	// -------------------------------------------------

	/**
	* Serialize geometries into a GPX string.
	*
	* @param Geometry $geometry
	*
	* @return string The GPX string representation of the input geometries and metdata (if present)
	*/

	public function write( Geometry $geometry, $namespace = FALSE ) {
		if ($geometry->isEmpty()) return NULL;
		if ($namespace) {
			$this->namespace = $namespace;
			$this->nss = $namespace.':';    
		}

		$gpx = '<' . $this->nss .'gpx xmlns="http://www.topografix.com/GPX/1/1" xmlns:gpxx="http://www.garmin.com/xmlschemas/GpxExtensions/v3" xmlns:gpxtpx="http://www.garmin.com/xmlschemas/TrackPointExtension/v1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" creator="geoPHPwithFeatures" version="1.1" xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd http://www.garmin.com/xmlschemas/GpxExtensions/v3 http://www.garmin.com/xmlschemas/GpxExtensionsv3.xsd http://www.garmin.com/xmlschemas/TrackPointExtension/v1 http://www.garmin.com/xmlschemas/TrackPointExtensionv1.xsd">';

		// if there is metadata associated with the top level geometry we add it as children to a <metadata> tag. 

		if (( $meta_data = $geometry->getMetaData()) != NULL ) {

			$gpx .= '<metadata>' . $this->metaDataToGPX( 'root', $meta_data ) . '</metadata>';

		}

		$gpx .= $this->geometryToGPX($geometry).'</'.$this->nss.'gpx>';

		return $gpx;

	} // end of write()

	// -------------------------------------------------

	/**
	* given a GPX string, generate geometries
	*/
  
	public function geomFromText( $text ) {

		// Change to lower-case and strip all CDATA
		// $text = strtolower($text);

		//  $text = preg_replace('/<!\[cdata\[(.*?)\]\]>/s','',$text);
    
		// Load into DOMDocument

		$xmlobj = new DOMDocument();

		@$xmlobj->loadXML($text);

		if ($xmlobj === false) {
			throw new Exception("Invalid GPX: ". $text);
		}
    
		$this->xmlobj = $xmlobj;

		try {
			$geom = $this->geomFromXML();
		} catch(InvalidText $e) {
			throw new Exception("Cannot Read Geometry From GPX: ". $text);
		} catch(Exception $e) {
			throw $e;
		}

		return $geom;

	} // end of geomFromText()

	// -------------------------------------------------
  
	/**
	* create geometry collection data structure from XML
	*
	* This method always returns a Geometry Collection even if the 
	* data involves only a single object.
	*
	* @return GeometryCollection
	*/

	protected function geomFromXML() {
		$geometries = array();
		$geometries = array_merge($geometries, $this->parseWaypoints());
		$geometries = array_merge($geometries, $this->parseTracks());
		$geometries = array_merge($geometries, $this->parseRoutes());
    
		if (empty($geometries)) {
			throw new Exception("Invalid / Empty GPX");
		}
    
		$geom_collection = new GeometryCollection( $geometries );

		// Reducing the geometry from the GPX file was causing far more problems
		// than it solved for our use case.
		//
		// $geom = geoPHP::geometryReduce($geometries); 

		// it's a bit forced, but we store the top level metadata info in the geometrycollection.

		$geom_collection->setMetaData( $this->parseMetaData() );

		return $geom_collection;

	} // end of geomFromXML()

	// -------------------------------------------------
  
	protected function childElements($xml, $nodename = '') {
		$children = array();
		foreach ($xml->childNodes as $child) {
			if ($child->nodeName == $nodename) {
				$children[] = $child;
			}
		}

		return $children;

	} // end of childElements()

	// -------------------------------------------------

	/**
	* parse the metadata tag
	*
	* There is only ever one of these tags in a GPX file.
	*
	* These metadata values are stored in the top geometrycollection object.
	*
	* @return array nested array of metadata.
	*
	* @link http://www.topografix.com/gpx/1/1/#type_metadataType
	*/
  
	protected function parseMetaData() {

		$meta_data_elements = $this->xmlobj->getElementsByTagName('metadata');

		if ( $meta_data_elements->length > 0 ) {
			return $this->parseMetaDataChildren( $meta_data_elements->item(0) );
		} else {
			return NULL;
		}

		

	} // end of parseMetaData()

	// -------------------------------------------------

	/**
	* parse waypoints
	*
	* Parses GPX wpt tags. Note that the metadata tags are not required in GPX.
	*
	* As a hack, to avoid having to refactor a bunch of existing code, we use the presence
	* of metadata to distinguish between a point in a LineString and a freestanding
	* waypoint. If no metadata exists, we include <fix>2d</fix> which should be ok to use 
	* in all waypoint instances when there is no metadata to indicate otherwise.
	*
	* @link http://www.topografix.com/gpx/1/1/#type_wptType
	*/
  
	protected function parseWaypoints() {
		$points = array();
		$wpt_elements = $this->xmlobj->getElementsByTagName('wpt');
		
		foreach ($wpt_elements as $wpt) {
			$lat = $wpt->attributes->getNamedItem("lat")->nodeValue;
			$lon = $wpt->attributes->getNamedItem("lon")->nodeValue;

			$elevation = NULL;

			// waypoint meta data.

			$meta_data = $this->parseMetaDataChildren( $wpt );

			if ( ! $meta_data ) {
				$meta_data = array(
					'fix' => '2d'
				);
			}

			if ( isset( $meta_data[ 'elevation' ] ) ) {
				$elevation = @$meta_data[ 'elevation' ];

				// we do not include elevation in the metadata. 
				// on generating GPX later on we used the Z coordinate
				// to generate the <ele> meta data tag.

				unset( $meta_data[ 'elevation' ] );
			}

			$points[] = new Point($lon, $lat, $elevation, $meta_data);

		}

		return $points;

	} // end of parseWayPoints()

	// -------------------------------------------------

	/**
	* parse tracks
	*
	* Each track is represented by a single linestring.
	*
	* @link http://www.topografix.com/gpx/1/1/#type_trkType
	*
	* @todo evaluate cases where a single track has multiple track segments.
	*/

	protected function parseTracks() {
		$lines = array();
		$trk_elements = $this->xmlobj->getElementsByTagName('trk');

		foreach ($trk_elements as $trk) {

			$components = array();
			$legs = array();

			foreach ($this->childElements($trk, 'trkseg') as $trkseg) {
				foreach ($this->childElements($trkseg, 'trkpt') as $trkpt) {
					$lat = $trkpt->attributes->getNamedItem("lat")->nodeValue;
					$lon = $trkpt->attributes->getNamedItem("lon")->nodeValue;

					$elevation = NULL;

					$meta_data = $this->parseMetaDataChildren( $trkpt );

					if ( isset( $meta_data[ 'elevation' ] ) ) {
						$elevation = @$meta_data[ 'elevation' ];

						unset( $meta_data[ 'elevation' ] );
					}

					$components[] = new Point($lon, $lat, $elevation, $meta_data );

				}

				$legs[] = new LineString( $components );

			}

			if ($legs) {

				// tracks may have a name and extensions childnodes.

				$track_meta_data = $this->parseMetaDataChildren( $trk );

				$track_meta_data[ 'line_type' ] = 'track';
                	
				$lines[] = new MultiLineString( $legs, $track_meta_data );

			}
		}

		return $lines;

	} // end of parseTracks()

	// -------------------------------------------------

	/**
	* parse routes
	*
	* Each route is represented by a multilinestring. The linestring 
	* consists of legs of coordinates that correspond to a set of way points.
	*
	* @link http://www.topografix.com/gpx/1/1/#type_rteType
	*/
  
	protected function parseRoutes() {
		$lines = array();
		$rte_elements = $this->xmlobj->getElementsByTagName('rte');

		foreach ( $rte_elements as $rte ) {
			$legs = array();
                        $endPoints = array();

			$route_meta_data = $this->parseMetaDataChildren( $rte );

			$route_meta_data[ 'line_type' ] = 'route';
			$route_meta_data[ 'waypoints' ] = array();

			// rtept represents a way point.

			foreach ( $this->childElements($rte, 'rtept') as $rtept ) {

				$lon = $rtept->attributes->getNamedItem("lon")->nodeValue;
				$lat = $rtept->attributes->getNamedItem("lat")->nodeValue;

				$endPoints[] = new Point( $lon, $lat );

				$meta_data = $this->parseMetaDataChildren( $rtept );

				// see parseRoutepointExtension. There is a final rtept that does not
				// have any coordinates

				if ( isset( $meta_data[ 'extensions' ] ) && 
					isset( $meta_data[ 'extensions' ][ 'gpxx_routepointextension' ] ) &&
					count( $meta_data[ 'extensions' ][ 'gpxx_routepointextension' ] ) > 0 ) {

					$legCoordinates = @$meta_data[ 'extensions' ][ 'gpxx_routepointextension' ];

					$legPoints = array();

					foreach ( $legCoordinates as $coordinates ) {
						$legPoints[] = new Point( $coordinates[0], $coordinates[1] );
					}

					unset( $meta_data[ 'extensions' ][ 'gpxx_routepointextension' ] );
					$legs[] = new LineString( $legPoints );

				}

				$wayPoint = $this->metaDataToWayPointFeature( $meta_data, $lat, $lon );
				$route_meta_data[ 'waypoints' ][] = $wayPoint;
        	

			}

			// It is possible that a route consists only of the rtept nodes without any calculated
			// coordinates, in which case we'll just use the endpoints as a placeholder.

			if ( count( $legs ) == 0 ) {
				$lines[] = new MultiLineString( [ new LineString( $endPoints ) ], $route_meta_data );
			} else {
				$lines[] = new MultiLineString( $legs, $route_meta_data );
			}
		}

	return $lines;

	} // end of parseRoutes()

	// -------------------------------------------------

	/**
	* given GPX metadata create a Point Feature
	*
	* Route waypoints are stored in the fourth position of the route 
	* coordinate array. These waypoints are stored as complete GeoJSON 
	* Feature objects.
	*/

	function metaDataToWayPointFeature( $meta_data, $lat, $lon ) {

		$feature = array( 
			'type' => 'Feature',
			'properties' => $meta_data,
			'geometry' => array(
				'type' => 'Point',
				'coordinates' => [ $lon, $lat ]
			)
		);

		return $feature;
	}

	// -------------------------------------------------

	/**
	* parse metadata child nodes 
	*
	* This parses both 'metadata' child nodes of wpt's, trks, etc 
	* but also the <metadata> tag below the root. It's a bit of a catch
	* all and there are paths, if the geometry data is not correct, where
	* it can generate invalid GPX.
	*
	* @param $node DOMNode root 
	* @return array list of meta data values
	*/

	protected function parseMetaDataChildren( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch ( strtolower( $child->nodeName )) {

				case 'name' :

					$meta_data[ 'name' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'cmt' :

					$meta_data[ 'cmt' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'link' :

					// there may be multiple links.

					$meta_data[ 'link' ][] = $this->parseLink( $child );

					break;

				case 'ele':

					$meta_data[ 'elevation' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'time':

					$meta_data[ 'time' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'desc':

					$meta_data[ 'desc' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'sym':

					$meta_data[ 'sym' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'src':

					$meta_data[ 'src' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'type':

					$meta_data[ 'type' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'keywords':

					$meta_data[ 'keywords' ] = html_entity_decode( $child->firstChild->nodeValue );

					break;

				case 'bounds':

					// this has four attributes that we need to 

					$meta_data[ 'bounds' ][ 'minlat' ] = $child->attributes->getNamedItem( 'minlat' )->nodeValue;
					$meta_data[ 'bounds' ][ 'minlon' ] = $child->attributes->getNamedItem( 'minlon' )->nodeValue;
					$meta_data[ 'bounds' ][ 'maxlat' ] = $child->attributes->getNamedItem( 'maxlat' )->nodeValue;
					$meta_data[ 'bounds' ][ 'maxlon' ] = $child->attributes->getNamedItem( 'maxlon' )->nodeValue;

					break;

				case 'extensions':

					$meta_data[ 'extensions' ] = $this->parseExtensions( $child );

					break;

			} // end of switch

		} // end of foreach

	return $meta_data;

	} // end of parseMetaDataChildren()

	// -------------------------------------------------

	/**  
	* parse Garmin meta data extensions
	*/

	protected function parseExtensions( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx:waypointextension':

					$meta_data[ 'gpxx_waypointextension' ] = $this->parseWaypointExtension( $child );

					break;

				case 'gpxx:routepointextension':

					$meta_data[ 'gpxx_routepointextension' ] = $this->parseRoutepointExtension( $child );

					break;

			}

		}

	return $meta_data;

	} // end of parseExtensions

	// -------------------------------------------------

	/**
	* parse a link node
	*
	* @link http://www.topografix.com/gpx/1/1/#type_linkType
	*/

	protected function parseLink( $node ) {

		$meta_data = [];

		$meta_data[ 'href' ] = $node->attributes->getNamedItem( 'href' )->nodeValue; 

		// may have a text and or type child

		foreach ( $node->childNodes as $child ) {

			switch( strtolower( $child->nodeName )) {

				case 'text':

					$meta_data[ 'text' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'type': // mime type of content

					$meta_data[ 'type' ] = html_entity_decode( $child->nodeValue );

					break;

			}

		}

		return $meta_data;

	} // end of parseLink()

	// -------------------------------------------------

	/**
	* parse a copyright node
	*
	* @link http://www.topografix.com/gpx/1/1/#type_copyrightType
	*/

	protected function parseCopyright( $node ) {

		$meta_data = [];

		$meta_data[ 'author' ] = $node->attributes->getNamedItem( 'author' )->nodeValue; 

		foreach ( $node->childNodes as $child ) {

			switch( strtolower( $child->nodeName )) {

				case 'year':

					$meta_data[ 'year' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'license': // uri of license

					$meta_data[ 'license' ] = html_entity_decode( $child->nodeValue );

					break;

			}

		}

		return $meta_data;

	} // end of parseCopyright()

	// -------------------------------------------------

	/**
	* parse an author node
	*
	* @link http://www.topografix.com/gpx/1/1/#type_personType
	*/

	protected function parseAuthor( $node ) {

		$meta_data = [];

		foreach ( $node->childNodes as $child ) {

			switch( strtolower( $child->nodeName )) {

				case 'name':

					$meta_data[ 'name' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'email': 

					$meta_data[ 'email' ][ 'id' ] = html_entity_decode( $child->attributes->getNamedItem( 'id' )->nodeValue ); 
					$meta_data[ 'email' ][ 'domain' ] = html_entity_decode( $child->attributes->getNamedItem( 'domain' )->nodeValue ); 

					break;

				case 'link': 

					$meta_data[ 'link' ] = $this->parseLink( $child );

					break;

			}

		}

		return $meta_data;


	} // end of parseAuthor()

	// -------------------------------------------------

	/**  
	* parse Garmin meta data extensions
	*/

	protected function parseWaypointExtension( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx:displaymode' :

					$meta_data[ 'gpxx_displaymode' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'gpxx:categories':

					$meta_data[ 'categories' ] = $this->parseCategories( $child );

					break;

				case 'gpxx:address':

					$meta_data[ 'gpxx_address' ] = $this->parseAddress( $child );

					break;

				case 'gpxx:phonenumber':

					$meta_data[ 'gpxx_phonenumber' ] = $this->parsePhoneNumbers( $child );

					break;
			}

		}

	return $meta_data;

	} // end of parseWaypointExtensions

	// -------------------------------------------------

	/**  
	* parse Garmin meta data extensions for routes
	*
	* I assume Garmin stores the calculated route under the point point extension to distinguish between routes 
	* calculated by the GPS unit itself and routes generated elsewhere.
	*
	* We ignore the gpxx:SubClass nodes. I'm not sure what they are used for.
	*
	* @return array of lat/lon coords. We are not using Point objects here. 
	*/

	protected function parseRoutepointExtension( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx:rpt' :

					$lat = $child->attributes->getNamedItem( 'lat' )->nodeValue;
					$lon = $child->attributes->getNamedItem( 'lon' )->nodeValue;

					// NOTE geoPHP uses lon before lat in arrays. This is also 
					// what's used in GeoJSON.

					$meta_data[] = array( $lon, $lat ); 

					break;
			}

		}

	return $meta_data;

	} // end of parseRoutepointExtensions

	// -------------------------------------------------

	/**  
	* parse Garmin meta data extensions
	*/

	protected function parseMbymcExtension( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'object_type_name' :

					$meta_data[ 'object_type_name' ] = html_entity_decode( $child->nodeValue );

					break;

			}

		}

	return $meta_data;

	} // end of parseMbymcExtension

	// -------------------------------------------------

	/**  
	* parse Garmin meta data extensions
	*/

	protected function parseCategories( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx:category':

					$meta_data[] = html_entity_decode( $child->nodeValue );

					break;

			}

		}

		return $meta_data;

	} // end of parseCategories

	// -------------------------------------------------

	/**  
	* parse Garmin address extension
	*/

	protected function parseAddress( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx:streetaddress' :

					$meta_data[ 'gpxx_streetaddress' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'gpxx:city' :

					$meta_data[ 'gpxx_city' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'gpxx:state' :

					$meta_data[ 'gpxx_state' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'gpxx:country' :

					$meta_data[ 'gpxx_country' ] = html_entity_decode( $child->nodeValue );

					break;

				case 'gpxx:postalcode' :

					$meta_data[ 'gpxx_postalcode' ] = html_entity_decode( $child->nodeValue );

					break;

			}

		}

	return $meta_data;

	} // end of parseAddress()

	// -------------------------------------------------

	/**  
	* parse phone numbers
	*/

	protected function parsePhoneNumbers( $node ) {

		$meta_data = [];

		foreach ($node->childNodes as $child) {

			switch( strtolower( $child->nodeName )) {

				case 'gpxx_phonenumber':

					if (( $category =  $child->attributes->getNamedItem("Category")->nodeValue) != NULL ) {
						$meta_data[ $category ] = html_entity_decode( $child->nodeValue );
					} else {
						$meta_data[ 'primary' ] = html_entity_decode( $child->nodeValue );
					}

					break;

			}

		}

		return $meta_data;

	} // end of parsePhoneNumbers

	// -------------------------------------------------
  
	protected function geometryToGPX($geom) {
		$type = strtolower($geom->getGeomType());

		switch ($type) {
			case 'point':
				return $this->pointToGPX($geom);
				break;
			case 'linestring':
				return $this->linestringToGPX($geom);
				break;
			case 'multilinestring':
				return $this->multilinestringToGPX($geom);
				break;
			case 'polygon':
			case 'multipoint':
			case 'multipolygon':
			case 'geometrycollection':
				return $this->collectionToGPX($geom);
				break;
		}

	} // end of geometryToGPX()

	// -------------------------------------------------

	/**
	* generate wpt xml
	*/
  
	private function pointToGPX($geom) {
		$gpx = '<'.$this->nss.'wpt lat="'.$geom->getY().'" lon="'.$geom->getX() . '"';

		$tagClosed = false;

		// do we have an elevation for this point? 

		if ( $geom->getZ() ) {

			$tagClosed = true;
			$gpx .= '><ele>' . $geom->getZ() . '</ele>';

		}

		if (( $meta_data = $geom->getMetaData()) != NULL ) {

			if ( ! $tagClosed ) {
				$tagClosed = true;
				$gpx .= '>';
			}

			$gpx .= $this->metaDataToGPX( 'wpt', $meta_data );

		}

		if ( $tagClosed ) {
			$gpx .= '</' . $this->nss . 'wpt>';
		} else {
			$gpx .= '/>';
		}

		return $gpx;

	} // end of pointToGPX()

	// -------------------------------------------------

	/**
	* Given a linestring generate a track or route
	*
	* @see multilinestringToGPX()
	*/
  
	private function linestringToGPX($geom) {

		$meta_data = $geom->getMetaData();

		if (( $meta_data == NULL ) || ( isset( $meta_data[ 'line_type' ] ) &&  $meta_data[ 'line_type' ] == 'track' )) { 

			$gpx = $this->linestringToTrk( $geom );

		} else {

			$gpx = $this->linestringToRte( $geom ) ;

		}

		return $gpx;

	}

	// ---------------------------------------------------

	/**
	* given a linestring generate a track
	*/

	private function linestringToTrk( $geom ) {

		$gpx = '<'.$this->nss.'trk>';

		// if metadata contains junk this may generate incorrect GPX.

		if (( $meta_data = $geom->getMetaData()) != NULL ) {
			$gpx .= $this->metaDataToGPX( 'trk', $meta_data );
		}

		// Not sure if this is correct, but it seems trkseg's 
		// don't support any feature metadata.

		$gpx .= '<' . $this->nss . 'trkseg>';
    
		foreach ($geom->getComponents() as $comp) {
			$gpx .= '<'.$this->nss.'trkpt lat="'.$comp->getY().'" lon="'.$comp->getX() . '"';

			$tagClosed = false;

			// do we have an elevation for this point? 

			if ( $comp->getZ() ) {

				$tagClosed = true;
				$gpx .= '><ele>' . $comp->getZ() . '</ele>';

			}

			if (( $meta_data = $comp->getMetaData()) != NULL ) {

				if ( ! $tagClosed ) {
					$tagClosed = true;
					$gpx .= '>';
				}

				$gpx .= $this->metaDataToGPX( 'trkseg', $meta_data );

			}

			if ( $tagClosed ) {
				$gpx .= '</' . $this->nss . 'trkpt>'; 
			} else {
				$gpx .= '/>';
			}

		} // end of foreach.
    
		$gpx .= '</'.$this->nss.'trkseg></'.$this->nss.'trk>';
    
		return $gpx;

	} // end of linestringToTrk()

	// ---------------------------------------------------

	/**
	* given a linestring generate a route
	*
	* Garmin RoutePointExtensions are stored under the metadata. 
	*/

	private function linestringToRte( $geom ) {

		$gpx = '<'.$this->nss.'rte>';

		// if metadata contains junk this may generate incorrect GPX.

		if (( $meta_data = $geom->getMetaData()) != NULL ) {
			$gpx .= $this->metaDataToGPX( 'rte', $meta_data );
		}

		foreach ($geom->getComponents() as $comp) {
			$gpx .= '<'.$this->nss.'rtept lat="'.$comp->getY().'" lon="'.$comp->getX() . '"';

			if (( $meta_data = $comp->getMetaData()) != NULL ) {
				$gpx .= '>' . $this->metaDataToGPX( 'rtept', $meta_data ) . '</' . $this->nss . 'rtept>';
			} else {
				$gpx .= '/>';
			}

		} // end of foreach.
    
		$gpx .= '</' . $this->nss . 'rte>';
    
		return $gpx;

	} // end of linestringToRte()

	// -------------------------------------------------

	/**
	* Given a multilinestring generate a track or route
	*
	* In the previous design, routes and tracks were stored as single linestrings
	* but using multilinestrings has proven to be more convenient.
	*/
  
	private function multilinestringToGPX($geom) {

		$meta_data = $geom->getMetaData();

		if (( $meta_data == NULL ) || ( isset( $meta_data[ 'line_type' ] ) &&  $meta_data[ 'line_type' ] == 'track' )) { 

			$gpx = $this->multilinestringToTrk( $geom );

		} else {

			$gpx = $this->multilinestringToRte( $geom ) ;

		}

		return $gpx;

	}

	// ---------------------------------------------------

	/**
	* given a multilinestring generate a track
	*/

	private function multilinestringToTrk( $geom ) {

		$gpx = '<'.$this->nss.'trk>';

		// if metadata contains junk this may generate incorrect GPX.

		if (( $meta_data = $geom->getMetaData()) != NULL ) {
			$gpx .= $this->metaDataToGPX( 'trk', $meta_data );
		}

		// Each linestring represents a track segment.

		foreach( $geom->getComponents() as $leg ) {

			// Not sure if this is correct, but it seems trkseg's 
			// don't support any feature metadata.

			$gpx .= '<' . $this->nss . 'trkseg>';
    
			foreach ($leg->getComponents() as $comp) {
				$gpx .= '<'.$this->nss.'trkpt lat="'.$comp->getY().'" lon="'.$comp->getX() . '"';

				$tagClosed = false;

				// do we have an elevation for this point? 

				if ( $comp->getZ() ) {

					$tagClosed = true;
					$gpx .= '><ele>' . $comp->getZ() . '</ele>';

				}

		
				if (( $meta_data = $comp->getMetaData()) != NULL ) {

					if ( ! $tagClosed ) {
						$tagClosed = true;
						$gpx .= '>';
					}

					$gpx .= $this->metaDataToGPX( 'trkpt', $meta_data );

				}

				if ( $tagClosed ) {
					$gpx .= '</' . $this->nss . 'trkpt>'; 
				} else {
					$gpx .= '/>';
				}

			} // end of loop over leg coordinates.

			$gpx .= '</'.$this->nss.'trkseg>';

		} // end of loop over legs.
    
		$gpx .= '</'.$this->nss.'trk>';
    
		return $gpx;

	} // end of multilinestringToTrk()

	// ---------------------------------------------------

	/**
	* given a multilinestring generate a route
	*
	* WayPoints are stored in the top level meta data. The route point extensions coordinates are
	* made up of the multilinestring legs.
	*/

	private function multilinestringToRte( $geom ) {

		$gpx = '<'.$this->nss.'rte>';

		// if metadata contains junk this may generate incorrect GPX.

		if (( $meta_data = $geom->getMetaData()) != NULL ) {
			$gpx .= $this->metaDataToGPX( 'rte', $meta_data );
		}

		$wayPoints = $meta_data[ 'waypoints' ];
		$legs = $geom->getComponents();
		$offset = 0;

		foreach ( $wayPoints as $wayPoint ) {

			$gpx .= '<'.$this->nss.'rtept lat="'. $wayPoint[ 'geometry' ][ 'coordinates' ][ 1 ] .'" lon="'.  $wayPoint[ 'geometry' ][ 'coordinates' ][ 0 ] . '">';

			if ( isset( $wayPoint[ 'properties' ][ 'name' ] )) {
				$gpx .= '<' . $this->nss . 'name><![CDATA[' . htmlentities( $wayPoint[ 'properties' ][ 'name' ]  ) . ']]></' . $this->nss . 'name>';
			} else {
				$wayPointNum = $offset + 1;
				$gpx .= '<' . $this->nss . 'name><![CDATA[' . "Way Point {$wayPointNum}" . ']]></' . $this->nss . 'name>';
			}

			// We have one more WayPoint than legs.

			if ( isset( $legs[ $offset ] ) ) {

				$leg = $legs[ $offset ];

				$gpx .= '<extensions>';
				$gpx .= '<gpxx:RoutePointExtension>';

				foreach ( $leg->getComponents() as $comp ) {
					$gpx .= '<'.$this->nss.'gpxx:rpt lat="'. $comp->getY() . '" lon="' . $comp->getX() . '"/>';
	 			}

				$gpx .= '</gpxx:RoutePointExtension>';
				$gpx .= '</extensions>';

			}

			$gpx .= '</rtept>';

			$offset++;
			
		} // end of loop over wayPoints.
    
		$gpx .= '</' . $this->nss . 'rte>';
    
		return $gpx;

	} // end of multilinestringToRte()

	// -------------------------------------------------

	/**
	* generate GPX from a geometrycollection
	*
	* Note that the elements in a GPX file are required to be in a specific order.
	* Notably the order is waypoints, routes, and then tracks.
	*/
  
	public function collectionToGPX($geom) {
		$gpx = '';
		$components = $geom->getComponents();

		// first waypoints

		foreach ( $components as $comp ) {
			$type = strtolower( $comp->getGeomType() );
			if ( $type == 'point' ) {
				$gpx .= $this->geometryToGPX($comp);
			}
		}

		// then routes

		foreach ( $components as $comp ) {
			$type = strtolower( $comp->getGeomType() );
			if (( $type == 'linestring' ) || ( $type == 'multilinestring' )) {
				$meta_data = $comp->getMetaData();
				if (( $meta_data != NULL ) && ( isset( $meta_data[ 'line_type' ] ) &&  $meta_data[ 'line_type' ] == 'route' )) { 
					$gpx .= $this->geometryToGPX($comp);
				}
			}
		}

		// then tracks

		foreach ( $components as $comp ) {
			$type = strtolower( $comp->getGeomType() );

			if (( $type == 'linestring' ) || ( $type == 'multilinestring' )) {
				$meta_data = $comp->getMetaData();

				if (( $meta_data == NULL ) || ( isset( $meta_data[ 'line_type' ] ) &&  $meta_data[ 'line_type' ] == 'track' )) { 
					$gpx .= $this->geometryToGPX($comp);
				}
			}
		}
		return $gpx;

	} // end of collectionToGPX()

	// -------------------------------------------------

	/**
	* Order as first waypoints, then routes, than tracks, then everything else
	*
	* @param $comp
	*
	* @return int
	*/

	protected function getComponentPrecedence($comp) {

		$type = strtolower( $comp->getGeomType() );

		if ($type == 'point') {
			return 1;
		}

		if ($type == 'linestring') {

			$meta_data = $comp->getMetaData();

			if (($meta_data != null) && (isset($meta_data['line_type']) && $meta_data['line_type'] == 'route')) {
				return 2;
			}

			return 3;
		}

		return 4;
	}

	// -------------------------------------------------

	/**
	* generate  metadata GPX child nodes 
	*
	* To add to our misery, the order of the nodes matters. If we do not generate them 
	* in the exact order specificed in the spec the generated GPX file will fail to validate.
	* 
	* @param string $type root, wpt, rte, rtept, trk, trkpt
	*/

	protected function metaDataToGPX( $type, $meta_data ) {

		$gpx = '';

		$nodeKeys = array(
			'name',
			'desc',
			'author',
			'copyright',
			'link',
			'time',
			'keywords',
			'bounds',
			'extensions'
		);

		// DEPRECATED: are we dealing with a route waypoint object in a route point object?
		// (WayPoints are now stored under the properties key at the root level of the route)

		if ( array_key_exists( 'type', $meta_data ) && (  @$meta_data[ 'type' ] == 'Feature' )) { 
			$meta_data = $meta_data[ 'properties' ];
		}

		// so we need to generate the metadata in a particular order. If we do not do it in this specific order the
		// resulting GPX file will fail to validate and Garmin Basecamp will refuse to import it.

		foreach ( $nodeKeys as $key ) {

			// we may not have this key in the data.

			if ( isset( $meta_data[ $key ] ) ) {

				$data = $meta_data[ $key ];

				switch ( $key ) {

					case 'name' :

						$gpx .= '<' . $this->nss . 'name><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'name>';

					break;

					case 'cmt' :

						$gpx .= '<' . $this->nss . 'cmt><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'cmt>';
                	
						break;

					case 'link' :

						$gpx .=  $this->linksToGPX( $data ) ;

					break;

					case 'url':

						$gpx .= '<' . $this->nss . 'url><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'url>';

					break;

					case 'ele':

						$gpx .= '<' . $this->nss . 'ele><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'ele>';

					break;

					case 'time':

						// time attributes only apply to track points

						if ( $type == 'trkpt' ) {

							$gpx .= '<' . $this->nss . 'time><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'time>';

						}
                	
					break;

					case 'desc':

						$gpx .= '<' . $this->nss . 'desc><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'desc>';

					break;

					case 'sym':

						$gpx .= '<' . $this->nss . 'sym><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'sym>';

					break;

					case 'type':

						$gpx .= '<' . $this->nss . 'type><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'type>';

					break;

					case 'author':

						$gpx .= $this->authorToGPX( $data );

					break;

					case 'copyright':

						$gpx .= $this->copyrightToGPX( $data );
                        	
					break;

					case 'extensions':

						$gpx .= $this->extensionsToGPX( $data );

					break;

				} // end of switch

			} // end of foreach

		} // end of foreach

	return $gpx;

	} // end of metaDataToGPX()

	// ------------------------------------------------

	/**
	* generate GPX for links
	*
	* There may be any number of links included in a GPX file
	*
	* @param array $meta_data array of link objects.
	*/

	protected function linksToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $offset => $link ) {

			$gpx .= '<link href="' . htmlentities( $link[ 'href' ] ) . '">';

			if ( isset( $link[ 'text' ] ) ) {
				$gpx .= '<text><![CDATA[' . htmlentities( $link[ 'text' ] ) . ']]></text>';
			}

			if ( isset( $link[ 'type' ] )) {
				$gpx .= '<type><![CDATA[' . htmlentities( $link[ 'type' ] ) . ']]></type>';
			}

			$gpx .= '</link>';
				
		}

		return $gpx;

	} // end of linksToGPX()

	// -------------------------------------------------

	/**  
	* generate Garmin meta data extensions
	*/

	protected function extensionsToGPX( $meta_data ) {

		$gpx = '<' . $this->nss . 'extensions>';

		$blank = true;

		foreach ( $meta_data as $key => $data ) {

			switch( $key ) {

				case 'gpxx_waypointextension':


					if (( $waypoint = $this->waypointExtensionToGPX( $data )) == '' ) {
						break;
					}

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:WaypointExtension>' . $waypoint  . '</' . $this->nss . 'gpxx:WaypointExtension>';

					break;

				case 'gpxx_routepointextension':

					if (( $routepoint = $this->routepointExtensionToGPX( $data )) == '' ) {
						break;
					}

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:RoutepointExtension>' . $routepoint . '</' . $this->nss . 'gpxx:RoutepointExtension>';

				break;

			}

		}

		if ( $blank ) { 
			return '';
		}

		$gpx .= '</' . $this->nss . 'extensions>'; 

		return $gpx;

	} // end of extensionsToGPX()

	// -------------------------------------------------

	/**  
	* generate Garmin waypoint extension
	*/

	protected function waypointExtensionToGPX( $meta_data ) {

		$gpx = '';

		$blank = true;

		foreach ( $meta_data as $key => $data ) {

			if (( is_array( $data ) && ( count( $data ) == 0 )) || ( $data == '' )) {
				continue;
			}

			switch( $key ) {

				case 'gpxx_displaymode' :

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:DisplayMode><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'gpxx:DisplayMode>';

					break;

				case 'gpxx_Categories':

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:Categories>' . $this->categoriesToGPX( $data ) . '</' . $this->nss . 'gpxx:Categories>';

					break;

				case 'gpxx_address':

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:Address>' . $this->addressToGPX( $data ) . '</' . $this->nss . 'gpxx:Address>';

					break;

				case 'gpxx_phonenumber':

					$blank = false;

					$gpx .= '<' . $this->nss . 'gpxx:PhoneNumber>' . $this->phonenumberToGPX( $data ) . '</' . $this->nss . 'gpxx:PhoneNumber>';

					break;
			}

		}

		if ( $blank ) {
			return '';
		}

		return $gpx;

	} // end of waypointExtensionsToGPX()

	// -------------------------------------------------

	/**  
	* generate Garmin routepoint extension points
	*
	* The routepoint extension data is a simple array of lat,lons
	*/

	protected function routepointExtensionToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $offset => $array ) {

			// geoJSON and geoPHP put lon before lat 

			$gpx .= '<' . $this->nss . 'gpxx:rpt lat="' . $array[1] . '" lon="' . $array[0] . '" />';

		}

	return $gpx;

	} // end of routepointExtensionsToGPX()

	// -------------------------------------------------

	/**  
	* generate Garmin category extensions
	*/

	protected function categoriesToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $key => $data ) {

			switch( $key ) {

				case 'gpxx_category':

					foreach ( $data as $category ) {

						$gpx .= '<' . $this->nss . 'gpxx:Category><![CDATA[' . htmlentities( $category ) . ']]></' . $this->nss . 'gpxx:Category>';

					}

					break;

			}

		}

		return $gpx;

	} // end of categoriesToGPX()

	// -------------------------------------------------

	/**  
	* generate Garmin address extension
	*/

	protected function addressToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $key => $data ) {

			switch( $key ) {

				case 'gpxx_streetaddress' :

					$gpx .= '<' . $this->nss . 'gpxx:StreetAddress><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'gpxx:StreetAddress>';

					break;

				case 'gpxx_city' :

					$gpx .= '<' . $this->nss . 'gpxx:City><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'gpxx:City>';

					break;

				case 'gpxx_state' :

					$gpx .= '<' . $this->nss . 'gpxx:State><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'gpxx:State>';

					break;

				case 'gpxx_postalcode' :

					$gpx .= '<' . $this->nss . 'gpxx:PostalCode><![CDATA[' . htmlentities( $data ) . ']]></' . $this->nss . 'gpxx:PostalCode>';

					break;

			}

		}

		return $gpx;

	} // end of addressToGPX()

	// -------------------------------------------------

	/**  
	* generate phone numbers
	*/

	protected function phoneNumbersToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $key => $data ) {

			switch( $key ) {

				case 'gpxx_phonenumber':

					foreach ( $data as $category => $value ) {

						$gpx .= '<' . $this->nss . 'gpxx:PhoneNumber';

						if ( $category != 'primary' ) {
							$gpx .= ' Category="' . htmlentities( $category ) . '"';
						}

						$gpx .= '><![CDATA[' . htmlentities( $value ) . ']]></' . $this->nss . 'gpxx:PhoneNumber>';

					}

					break;

			}

		}

		return $gpx;

	} // end of phoneNumbersToGPX()

	// -------------------------------------------------

	/**
	* generate a copyright node
	*
	* @link http://www.topografix.com/gpx/1/1/#type_copyrightType
	*/

	protected function copyrightToGPX( $meta_data ) {

		$gpx = '<copyright author="' . htmlentities( $meta_data[ 'author' ] ) . '">';

		if ( isset( $meta_data[ 'year' ] )) {
			$gpx .= '<year><![CDATA[' . htmlentities( $meta_data[ 'year' ] ) . ']]></year>';

		}

		if ( isset( $meta_data[ 'license' ] )) {

			$gpx .= '<license><![CDATA[' . htmlentities( $meta_data[ 'license' ] ) . ']]></license>';
		}

		return $gpx;

	} // end of copyrightToGPX()

	// -------------------------------------------------

	/**
	* generate an author node
	*
	* @link http://www.topografix.com/gpx/1/1/#type_personType
	*/

	protected function authorToGPX( $meta_data ) {

		$gpx = '';

		foreach ( $meta_data as $key => $data ) {

			switch( $key ) {

				case 'name':

					$gpx .= '<name><![CDATA[' . htmlentities( $meta_data[ 'name' ] ) . ']]></name>';

					break;

				case 'email': 

					$gpx .= '<email>';

					$gpx .= '<id><![CDATA[' . htmlentities( $meta_data[ 'email' ][ 'id' ] ) . ']]></id>';
					$gpx .= '<domain><![CDATA[' . htmlentities( $meta_data[ 'domain' ] ) . ']]></domain>';

					break;

				case 'link': 

					$gpx .= $this->linkToGPX( $data );

					break;

			}

		}

		return $gpx;


	} // end of authorToGPX()

} // end of class GPX

// END
