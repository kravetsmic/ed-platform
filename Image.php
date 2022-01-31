<?php

namespace App\Models\Image;

use App\Models\Gallery\Gallery;
use App\Models\Place\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Image extends Model
{
    use SoftDeletes;

    private $default_language = 1;

    protected $appends = ['place_select', 'vehicles', 'galleries', 'published', 'date_readable'];
    protected $casts = [
        'created_at' => "datetime:Y-m-d H:i",
        'published_at' => "timestamp",
    ];

    public function getDateAttribute($value)
    {
        return date('Y-m-d', strtotime($value));
    }

    public function getDateReadableAttribute()
    {
        $timestamp = strtotime($this->date);
        return [date('j \x\x\x Y', $timestamp), date('m', $timestamp)];
    }

    public function getPublishedAttribute()
    {
        return date('Y-m-d H:i', $this->published_at);
    }

    public function getPlaceSelectAttribute()
    {
        $place = [];

        $default_language = $this->default_language;
        $language_id = request()['language_id'];
        $place_id = $this->place_id;

        $locations = Place::select(DB::raw('places.id as id, IFNULL(p2.name, place_translations.name) as name, pn.name as native_name'))->
        leftJoin('place_translations', function($q) use ($default_language, $place_id) {
            $q->on('places.id', '=', 'place_translations.place_id')
                ->where('place_translations.language_id', '=', $default_language);
        })->

        leftJoin('place_translations as p2', function($q) use ($language_id, $place_id) {
            $q->on('place_translations.place_id', '=', 'p2.place_id')
                ->where('p2.language_id', '=', $language_id);
        })->
        leftJoin('place_translations as pn', function($q) use ($language_id, $place_id) {
            $q->on('place_translations.place_id', '=', 'pn.place_id')
                ->where('pn.language_id', '<>', $language_id)->where('pn.native', 1);
        })->
        where('places.id', $place_id)->first();

        if ($locations) {
            array_unshift($place, $locations);
        }

        if ($this->place2_id) {
            $place_id = $this->place2_id;

            $locations = Place::select(DB::raw('places.id as id, IFNULL(p2.name, place_translations.name) as name, pn.name as native_name'))->
            leftJoin('place_translations', function ($q) use ($default_language, $place_id) {
                $q->on('places.id', '=', 'place_translations.place_id')
                    ->where('place_translations.language_id', '=', $default_language);
            })->

            leftJoin('place_translations as p2', function ($q) use ($language_id, $place_id) {
                $q->on('place_translations.place_id', '=', 'p2.place_id')
                    ->where('p2.language_id', '=', $language_id);
            })->
            leftJoin('place_translations as pn', function($q) use ($language_id, $place_id) {
                $q->on('place_translations.place_id', '=', 'pn.place_id')
                    ->where('pn.language_id', '<>', $language_id)->where('pn.native', 1);
            })->
            where('places.id', $place_id)->first();
            if ($locations) {
                array_unshift($place, $locations);
            }
        }

        if ($this->place3_id) {
            $place_id = $this->place3_id;

            $locations = Place::select(DB::raw('places.id as id, IFNULL(p2.name, place_translations.name) as name, pn.name as native_name'))->
            leftJoin('place_translations', function ($q) use ($default_language, $place_id) {
                $q->on('places.id', '=', 'place_translations.place_id')
                    ->where('place_translations.language_id', '=', $default_language);
            })->

            leftJoin('place_translations as p2', function ($q) use ($language_id, $place_id) {
                $q->on('place_translations.place_id', '=', 'p2.place_id')
                    ->where('p2.language_id', '=', $language_id);
            })->
            leftJoin('place_translations as pn', function($q) use ($language_id, $place_id) {
                $q->on('place_translations.place_id', '=', 'pn.place_id')
                    ->where('pn.language_id', '<>', $language_id)->where('pn.native', 1);
            })->
            where('places.id', $place_id)->first();
            if ($locations) {
                array_unshift($place, $locations);
            }
        }
        return $place;
    }

    public function getGalleriesAttribute()
    {
        $default_language = $this->default_language;
        $language_id = request()['language_id'];
        $image_id = $this->id;

        $galleries = Gallery::select(DB::raw('galleries.id as id, IFNULL(g2.name, gallery_translations.name) as name'))->
        leftJoin('gallery_translations', function($q) use ($default_language) {
            $q->on('galleries.id', '=', 'gallery_translations.gallery_id')
                ->where('gallery_translations.language_id', '=', $default_language);
        })->

        leftJoin('gallery_translations as g2', function($q) use ($language_id) {
            $q->on('gallery_translations.gallery_id', '=', 'g2.gallery_id')
                ->where('g2.language_id', '=', $language_id);
        })->
        whereIn('galleries.id', function($q) use ($image_id) {
            $q->select('gallery_id')->from('image_galleries')->where('image_id', $image_id)->get();
        })->orderBy('name')->
        get();
        return $galleries;
    }

    public function getVehiclesAttribute()
    {
        $default_language = $this->default_language;
        $language_id = request()['language_id'];
        $image_id = $this->id;

        $vehicles = ImageVehicle::select(DB::raw('image_vehicles.vehicle_id as id, IFNULL(v2.name, vehicle_translations.name) as name, vehicle_text'))->
        leftJoin('vehicle_translations', function($q) use ($default_language) {
            $q->on('image_vehicles.vehicle_id', '=', 'vehicle_translations.vehicle_id')
                ->where('vehicle_translations.language_id', '=', $default_language);
        })->
        leftJoin('vehicle_translations as v2', function($q) use ($language_id) {
            $q->on('image_vehicles.vehicle_id', '=', 'v2.vehicle_id')
                ->where('v2.language_id', '=', $language_id);
        })->
        where('image_id', $this->id)->get();

        return $vehicles;
    }

    public function user()
    {
        return $this->hasOne('App\Models\User\User', 'id', 'user_id');
    }

    public function exif()
    {
        return $this->hasMany('App\Models\Image\ImageExif', 'image_id', 'id');
    }
}
