<?php

namespace App\Services\Image;

use App\Helpers\MessageStr;
use App\Models\Gallery\Gallery;
use App\Models\Image\Image;
use App\Models\Image\ImageApprove;
use App\Models\Image\ImageComment;
use App\Models\Image\ImageExif;
use App\Models\Image\ImageFavorite;
use App\Models\Image\ImageGallery;
use App\Models\Image\ImageLike;
use App\Models\Image\ImageLog;
use App\Models\Image\ImageStat;
use App\Models\Image\ImageVehicle;
use App\Models\Image\ImageView;
use App\Models\Place\Place;
use App\Models\Rating\RatingDay;
use App\Models\Rating\RatingHour;
use App\Models\Rating\RatingMonth;
use App\Models\Site\SearchQuery;
use App\Models\Site\TmpUpload;
use App\Models\User\User;
use App\Models\User\UserMeta;
use App\Models\User\UserSession;
use App\Models\Vehicle\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use \Intervention\Image\Facades\Image as IImage;

class ImageService
{
    private $per_page = 50;
    private $language_id = 1;
    private $exif_fields = [
        'make' => false,
        'model' => false,
        'software' => false,
        'exposuretime' => false,
        'fnumber' => true,
        'isospeedratings' => false,
        'datetimeoriginal' => false,
        'focallengthin35mmfilm' => false,
        'artist' => false,
        'copyright' => false,
        'undefinedtag:0xa434' => false,
        'focallength' => true
    ];

    public function list($user_id, $page, $type)
    {
        $images = Image::orderBy('id', 'desc')->skip($page * $this->per_page)->take($this->per_page);
        switch ($type) {
            case 'rejected':
                $images->where('approved', -1);
                break;
            case 'approved':
                $images->where('approved', 1);
                break;
            case 'not-moderated':
                $images->where('approved', 0);
                break;
        }
        $images->select(['images.id', 'lat', 'lon', 'approved', 'created_at', 'published_at', 'description', 'description_alt', 'file', 'place', 'place_id', 'place2_id', 'place3_id', 'title', 'title_alt']);
        $images->addSelect(['my_approve' => function ($q) use ($user_id) {
            $q->selectRaw('count(id)')->from('image_approves')->where('moderator_id', $user_id)->whereRaw('image_id = images.id');
        }]);
        $images->addSelect([DB::raw('IF (approved<>0, images.user_id, NULL) as user_id')]);
        $images->addSelect([DB::raw('IF (approved<>0, user_metas.name, NULL) as name')]);
        $images->where(function ($q) use ($user_id) {
            $q->where('images.user_id', '<>', $user_id)->orWhere('approved', '<>', 0);
        });
        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        return $images->get();
    }

    public function getTopHour($skip_vertical = false)
    {
        $image = RatingHour::orderBy('rating', 'DESC')->first();
        if (!$image) {
            return false;
        }
        $images = Image::select(['images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'user_metas.name', 'vertical']);
        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        $images->where('approved', 1);
        $images->where('visible', 1);
        $images->where('images.id', $image->image_id);
        if ($skip_vertical) {
            $images->where('vertical', 0);
        }
        return $images->first();
    }

    public function getTopDay($skip_vertical = false)
    {
        $image = RatingDay::orderBy('rating', 'DESC')->first();

        if (!$image) {
            return false;
        }

        $images = Image::select(['images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'user_metas.name', 'vertical']);
        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        $images->where('approved', 1);
        $images->where('visible', 1);
        $images->where('images.id', $image->image_id);
        if ($skip_vertical) {
            $images->where('vertical', 0);
        }
        return $images->first();
    }

    public function getTopMonth($skip_vertical = false)
    {
        $image = RatingMonth::orderBy('rating', 'DESC')->first();

        if (!$image) {
            return false;
        }

        $images = Image::select(['images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'user_metas.name', 'vertical']);
        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        $images->where('approved', 1);
        $images->where('visible', 1);
        $images->where('images.id', $image->image_id);
        if ($skip_vertical) {
            $images->where('vertical', 0);
        }

        return $images->first();
    }

    public function prevImage($id)
    {
        $prev = DB::table('images')->select('id')
            ->where('published_at', '>', function ($q) use ($id) {
                $q->select('published_at')->from('images')->where('id', $id);
            })
            ->where('approved', 1)
            ->where('visible', 1)
            ->whereNull('deleted_at')
            ->orderBy('published_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
        return $prev ? $prev->id : null;
    }

    public function nextImage($id)
    {
        $next = DB::table('images')->select('id')
            ->where('published_at', '<', function ($q) use ($id) {
                $q->select('published_at')->from('images')->where('id', $id);
            })
            ->where('approved', 1)
            ->where('visible', 1)
            ->whereNull('deleted_at')
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        return $next ? $next->id : null;
    }

    public function getTopRandom($skip_ids)
    {
        $images = Image::inRandomOrder()->select(['images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'user_metas.name', 'vertical']);
        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        $images->where('approved', 1);
        $images->where('visible', 1);
        $images->whereNotIn('images.id', $skip_ids);
        $images->where('vertical', 0);
        return $images->first();
    }

    public function getImagesCoords()
    {
        $images = DB::table('images')->select(['id', 'lat', 'lon'])->whereNotNull('lat')->whereNotNull('lon')->where('approved', 1)->where('visible', 1)->get();
        return $images;
    }

    public function listClient($filters, $skip_vertical, $skip_search)
    {
        $images = Image::orderBy('published_at', 'DESC')->orderBy('id', 'DESC')->skip($filters['skip'])->take($filters['take']);
        $images->select(['images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'user_metas.name', 'vertical']);

        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');
        if (isset($filters['filters']['approved'])) {
            $images->addSelect('images.approved');
            if ($filters['filters']['approved'] != 'all') {
                $images->where('approved', $filters['filters']['approved'] == 'under-review' ? 0 : ($filters['filters']['approved'] == 'approved' ? 1 : -1));
            }
        } else {
            $images->where('approved', 1);
        }
        if (isset($filters['filters']['visible'])) {
            $images->addSelect('images.visible');
            if ($filters['filters']['visible'] != 'all') {
                $images->where('visible', $filters['filters']['visible'] == 'visible' ? 1 : 0);
            }
        } else {
            $images->where('visible', 1);
        }
        if (isset($filters['filters']['favorites'])) {
            $user_id = $filters['filters']['favorites']['user_id'];
            $images->whereIn('images.id', function ($q) use ($user_id) {
                $q->select('image_id')->from('image_favorites')->where('user_id', $user_id);
            });
        }
        if (isset($filters['filters']['vehicle_id'])) {
            $vehicle_id = $filters['filters']['vehicle_id'];
            $images->whereIn('images.id', function ($q) use ($vehicle_id) {
                $q->select('image_id')->from('image_vehicles')->where('vehicle_id', $vehicle_id)->whereNull('deleted_at');
            });
        }
        if (isset($filters['filters']['gallery_id'])) {
            $gallery_id = $filters['filters']['gallery_id'];
            $images->whereIn('images.id', function ($q) use ($gallery_id) {
                $q->select('image_id')->from('image_galleries')->where('gallery_id', $gallery_id);
            });
        }
        if (isset($filters['filters']['place_id'])) {
            $place_id = $filters['filters']['place_id'];
            $images->where('place_id', $place_id)->orWhere('place2_id', $place_id)->orWhere('place3_id', $place_id);

            $images->leftJoin('places', function ($q) {
                $q->on('images.place_id', '=', 'places.id')->whereNull('places.deleted_at');
            }
            );
            $images->leftJoin('places as p2', function ($q) {
                $q->on('images.place2_id', '=', 'p2.id')->whereNull('p2.deleted_at');
            }
            );
            $images->leftJoin('places as p3', function ($q) {
                $q->on('images.place3_id', '=', 'p3.id')->whereNull('p3.deleted_at');
            }
            );
        }
        if (isset($filters['filters']['user_id'])) {
            $images->where('images.user_id', $filters['filters']['user_id']);
        }
        if (isset($filters['filters']['date'])) {
            $images->where('images.date', '>=', $filters['filters']['date'] . ' 00:00:00')->where('images.date', '<=', $filters['filters']['date'] . ' 23:59:59');
        }
        if (isset($filters['filters']['search'])) {
            if (!$skip_search) {
                $query = new SearchQuery();
                $query->search = $filters['filters']['search'];
                $query->save();
            }
            $search = mb_strtolower($filters['filters']['search']);
            $search = mb_ereg_replace('[\,\s\|]', ' ', $search);
            $search = mb_ereg_replace('\s+', ' ', $search);
            $search = mb_ereg_replace('\s', '|', $search);
            $images->where(function ($p) use ($search) {
                $p->where(DB::raw('LOWER (images.title)'), 'regexp', $search)
                    ->orWhere(DB::raw('LOWER (images.title_alt)'), 'regexp', $search)
                    ->orWhere(DB::raw('LOWER (images.description)'), 'regexp', $search)
                    ->orWhere(DB::raw('LOWER (images.description_alt)'), 'regexp', $search)
                    ->orWhereIn('images.user_id', function ($q) use ($search) {
                        $q->select('user_id')->from('user_metas')
                            ->leftJoin('users', 'user_metas.user_id', '=', 'users.id')
                            ->whereNull('users.deleted_at')
                            ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                    })
                    ->orWhereIn('images.id', function ($q) use ($search) {
                        $q->select('image_id')->from('image_vehicles')->whereIn('vehicle_id', function ($q2) use ($search) {
                            $q2->select('vehicle_id')->from('vehicle_translations')
                                ->leftJoin('vehicles', 'vehicle_translations.vehicle_id', '=', 'vehicles.id')
                                ->whereNull('vehicles.deleted_at')
                                ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                        });
                    })
                    ->orWhereIn('images.place3_id', function ($q) use ($search) {
                        $q->select('place_id')->from('place_translations')
                            ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                            ->whereNull('places.deleted_at')
                            ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                    })
                    ->orWhereIn('images.place2_id', function ($q) use ($search) {
                        $q->select('place_id')->from('place_translations')
                            ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                            ->whereNull('places.deleted_at')
                            ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                    })
                    ->orWhereIn('images.place_id', function ($q) use ($search) {
                        $q->select('place_id')->from('place_translations')
                            ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                            ->whereNull('places.deleted_at')
                            ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                    });
            });
        }
        if ($skip_vertical) {
            $images->where('vertical', 0);
        }

        return $images->get();
    }

    public function getImage($id, $user_id)
    {
        $image = Image::select([
            DB::raw('image_likes.like as my_like'),
            DB::raw('image_favorites.user_id as my_favorite'), 'place', 'approved', 'like_count', 'dislike_count', 'view_count', 'images.id', 'images.user_id', 'images.created_at', 'published_at', 'description', 'description_alt', 'file', 'place_id', 'place2_id', 'place3_id', 'title', 'title_alt', 'user_metas.name', 'date', 'lat', 'lon'
        ])
            ->where('images.id', $id)
            ->where(function ($q) use ($user_id) {
                $q->where('approved', 1)->orWhere('images.user_id', $user_id);
            });

        $image->leftJoin('image_likes', function ($q) use ($user_id) {
            $q->on('images.id', '=', 'image_likes.image_id')->where('image_likes.user_id', $user_id);
        });

        $image->leftJoin('image_favorites', function ($q) use ($user_id) {
            $q->on('images.id', '=', 'image_favorites.image_id')->where('image_favorites.user_id', $user_id);
        });

        $image->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id')->with('exif');
        $result = $image->first();

        if (!$user_id) {
            $session_data = request()->session_data ?? [];
            $likes = $session_data['images_likes'] ?? [];
            $result->my_like = isset($likes[$id])?$likes[$id]:null;
        }

        return $result;
    }

    public function getImageRaw($id)
    {
        return Image::where('id', $id)->first();
    }

    public function deleteComment($comment)
    {
        $image = $this->getImageRaw($comment->image_id);
        if (!$image) {
            return false;
        }

        $stat = ImageStat::where('image_id', $comment->image_id)->first();

        if (!$stat) {
            return false;
        }

        $comment_text = mb_ereg_replace('\<.*?\>', ' ', $comment->comment);
        $comment_text = mb_ereg_replace('\s\s', ' ', $comment_text);
        $words = explode(' ', trim($comment_text));
        $comment_min_words = Config::get('site.comment_min_words', 1);

        $image_timestamp = $image->published_at ? $image->published_at : 0;
        $comment_days = (strtotime($comment->created_at) - $image_timestamp) / 60 / 60 / 24;
        $image_1 = $comment_days <= 1;
        $image_2 = $comment_days <= 2;
        $image_3 = $comment_days <= 3;
        $image_7 = $comment_days <= 7;

        $stat->comments_all = $stat->comments_all - 1;
        if ((now()->timestamp - strtotime($comment->created_at)) / 60 / 60 < 1) {
            $stat->comments_all_hour = $stat->comments_all_hour - 1;
        }

        if (count($words) >= $comment_min_words) {
            $stat->comments_1 = $stat->comments_1 - (int)$image_1;
            $stat->comments_2 = $stat->comments_2 - (int)$image_2;
            $stat->comments_3 = $stat->comments_3 - (int)$image_3;
            $stat->comments_7 = $stat->comments_7 - (int)$image_7;
            $stat->comments = $stat->comments - 1;
            if ((now()->timestamp - strtotime($comment->created_at)) / 60 / 60 < 1) {
                $stat->comments_hour = $stat->comments_hour - 1;
            }
        }
        $stat->save();
        $comment->delete();
        return;
    }

    public function countClient($filters, $skip_vertical)
    {
        $images = Image::where('images.id', '>', 0);
        if (isset($filters['approved'])) {
            if ($filters['approved'] != 'all') {
                $images->where('approved', $filters['approved'] == 'under-review' ? 0 : ($filters['approved'] == 'approved' ? 1 : -1));
            }
        } else {
            $images->where('approved', 1);
        }
        if (isset($filters['visible'])) {
            if ($filters['visible'] != 'all') {
                $images->where('visible', $filters['visible'] == 'visible' ? 1 : 0);
            }
        } else {
            $images->where('visible', 1);
        }
        if (isset($filters['favorites'])) {
            $user_id = $filters['favorites']['user_id'];
            $images->whereIn('images.id', function ($q) use ($user_id) {
                $q->select('image_id')->from('image_favorites')->where('user_id', $user_id);
            });
        }

        if (isset($filters['vehicle_id'])) {
            $vehicle_id = $filters['vehicle_id'];
            $images->whereIn('images.id', function ($q) use ($vehicle_id) {
                $q->select('image_id')->from('image_vehicles')->where('vehicle_id', $vehicle_id);
            });
        }
        if (isset($filters['gallery_id'])) {
            $gallery_id = $filters['gallery_id'];
            $images->whereIn('images.id', function ($q) use ($gallery_id) {
                $q->select('image_id')->from('image_galleries')->where('gallery_id', $gallery_id);
            });
        }

        if (isset($filters['place_id'])) {
            $place_id = $filters['place_id'];
            $images->where('place_id', $place_id)->orWhere('place2_id', $place_id)->orWhere('place3_id', $place_id);

            $images->leftJoin('places', function ($q) {
                $q->on('images.place_id', '=', 'places.id')->whereNull('places.deleted_at');
            }
            );
            $images->leftJoin('places as p2', function ($q) {
                $q->on('images.place2_id', '=', 'p2.id')->whereNull('p2.deleted_at');
            }
            );
            $images->leftJoin('places as p3', function ($q) {
                $q->on('images.place3_id', '=', 'p3.id')->whereNull('p3.deleted_at');
            }
            );
        }
        if (isset($filters['user_id'])) {
            $images->where('user_id', $filters['user_id']);
        }
        if (isset($filters['date'])) {
            $images->where('images.date', '>=', $filters['date'] . ' 00:00:00')->where('images.date', '<=', $filters['date'] . ' 23:59:59');
        }
        if (isset($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $search = mb_ereg_replace('[\.\,\s\|]', ' ', $search);
            $search = mb_ereg_replace('\s+', ' ', $search);
            $search = mb_ereg_replace('\s', '|', $search);
            $images->where(DB::raw('LOWER (images.title)'), 'regexp', $search)
                ->orWhere(DB::raw('LOWER (images.description)'), 'regexp', $search)
                ->orWhereIn('images.user_id', function ($q) use ($search) {
                    $q->select('user_id')->from('user_metas')
                        ->leftJoin('users', 'user_metas.user_id', '=', 'users.id')
                        ->whereNull('users.deleted_at')
                        ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                })
                ->orWhereIn('images.id', function ($q) use ($search) {
                    $q->select('image_id')->from('image_vehicles')->whereIn('vehicle_id', function ($q2) use ($search) {
                        $q2->select('vehicle_id')->from('vehicle_translations')
                            ->leftJoin('vehicles', 'vehicle_translations.vehicle_id', '=', 'vehicles.id')
                            ->whereNull('vehicles.deleted_at')
                            ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                    });
                })
                ->orWhereIn('images.place3_id', function ($q) use ($search) {
                    $q->select('place_id')->from('place_translations')
                        ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                        ->whereNull('places.deleted_at')
                        ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                })
                ->orWhereIn('images.place2_id', function ($q) use ($search) {
                    $q->select('place_id')->from('place_translations')
                        ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                        ->whereNull('places.deleted_at')
                        ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                })
                ->orWhereIn('images.place_id', function ($q) use ($search) {
                    $q->select('place_id')->from('place_translations')
                        ->leftJoin('places', 'place_translations.place_id', '=', 'places.id')
                        ->whereNull('places.deleted_at')
                        ->where(DB::raw('LOWER (name)'), 'regexp', $search);
                });
        }

        if ($skip_vertical) {
            $images->where('vertical', 0);
        }

        return $images->count();
    }

    public function getRandomList($user_id, $image_id)
    {
        $images = Image::inRandomOrder()->limit(6)->where('images.id', '<>', $image_id)->where('images.user_id', '<>', $user_id)
            ->where('images.user_id', '<>', function ($q) use ($image_id) {
                $q->select('user_id')->from('images')->where('id', $image_id);
            });
        $images->where('approved', 1);
        $images->where('vertical', 0);
        $images->where('visible', 1);

        $images->select(['images.id', 'images.user_id', 'created_at', 'published_at', 'description', 'file', 'place_id', 'title', 'user_metas.name']);

        $images->leftJoin('user_metas', 'images.user_id', '=', 'user_metas.user_id');

        return $images->get();
    }

    public function approve($user_id, $image_id, $approve_type, $comment)
    {
        $image = Image::where('id', $image_id)->first();
        if (!$image) {
            return false;
        }
        if ($image->user_id == $user_id) {
            return false;
        }

        if (ImageApprove::where('image_id', $image_id)->where('moderator_id', $user_id)->first()) {
            return false;
        }
        $approve = new ImageApprove();
        $approve->image_id = $image_id;
        $approve->moderator_id = $user_id;
        $approve->approved = $approve_type;
        $approve->comment = $comment;
        $approve->save();

        $approved_count = ImageApprove::where('image_id', $image_id)->where('approved', $approve_type)->count();

        /* todo old style approve
        $total_approves = ImageApprove::where('image_id', $image_id)->count();
        $moderators_count = UserRole::whereIn('role_id', function($q) {
            $q->select('role_id')->from('role_permissions')->where('permission_id', function($q2) {
                $q2->select('id')->from('permissions')->where('slug', 'image.approve')->get();
            });
        })->where('user_id', '<>', $user_id)->distinct('user_id')->count();

        if (($approved_count > $moderators_count / 2)) {
            $image->approved = $approve_type ? 1 : -1;

            if ($image->approved == 1) {
                $image->published_at = Carbon::now();
            }
        } else {
            if (($moderators_count == 2 && $total_approves == 2)) {
                $coin = rand(0,10);
                $approve->coin = $coin;
                $approve->save();
                $image->approved = $coin >= 5 ? 1 : -1;

                if ($image->approved == 1) {
                    $image->published_at = Carbon::now();
                }
            }
        }*/
        if (($approved_count >= 2)) {
            $image->approved = $approve_type ? 1 : -1;

            if ($image->approved == 1) {
                $image->published_at = Carbon::now();
            }
        }
        $image->save();
        return true;
    }

    public function update($user_id, $image_id, $data)
    {
        $image = Image::where('id', $image_id)->first();
        if (!$image) {
            return false;
        }
        if (isset($data['title'])) {
            $image->title = $data['title'] ? mb_substr($data['title'], 0, 240) : '';
            if ($user_id != $image->user_id) {
                $image->moderator_title_changed = true;
            }
        }
        if (isset($data['description'])) {
            $image->description = $data['description'] ? $data['description'] : '';
            if ($user_id != $image->user_id) {
                $image->moderator_description_changed = true;
            }
        }

        if (isset($data['title_alt'])) {
            $image->title_alt = $data['title_alt'] ? mb_substr($data['title_alt'], 0, 240) : '';
        }
        if (isset($data['description_alt'])) {
            $image->description_alt = $data['description_alt'] ? $data['description_alt'] : '';
        }
        if (isset($data['place'])) {
            $place_id = isset($data['place']['id']) ? $data['place']['id'] : null;
            if ($place_id) {
                $image->place_id = $place_id;
                $place = Place::find($place_id);
                if ($place && $place->parent_id) {
                    $place2_id = $place->parent_id;
                    if ($place2_id) {
                        $image->place2_id = $place2_id;
                        $place2 = Place::find($place2_id);
                        if ($place2 && $place2->parent_id) {
                            $place3_id = $place2->parent_id;
                            if ($place3_id) {
                                $image->place3_id = $place3_id;
                            }
                        }
                    }
                }
                if ($user_id != $image->user_id) {
                    if ($place && $place->created_at < $image->created_at) {
                        $image->moderator_place_changed = true;
                    }
                }
            }

        }
        if (isset($data['galleries'])) {
            ImageGallery::where('image_id', $image_id)->delete();
            $already_set = [];
            foreach ($data['galleries'] as $gallery) {
                if (!in_array($gallery['id'], $already_set)) {
                    ImageGallery::insert(['image_id' => $image_id, 'gallery_id' => $gallery['id']]);
                    $already_set[] = $gallery['id'];

                    $gallery_data = Gallery::find($gallery['id']);
                    if ($user_id != $image->user_id) {
                        if ($gallery_data && $gallery_data->created_at < $image->created_at) {
                            $image->moderator_gallery_changed = true;
                        }
                    }
                }
            }
        }
        if (isset($data['vehicles'])) {
            ImageVehicle::where('image_id', $image_id)->delete();
            $already_set = [];
            if (is_array($data['vehicles'])) {
                foreach ($data['vehicles'] as $vehicle) {
                    if (isset($vehicle['id']) && !in_array($vehicle['id'], $already_set)) {
                        ImageVehicle::insert(['image_id' => $image_id, 'vehicle_id' => $vehicle['id'], 'vehicle_text' => isset($vehicle['vehicle_text']) ? $vehicle['vehicle_text'] : null]);
                        $already_set[] = $vehicle['id'];

                        $vehicle_data = Vehicle::find($vehicle['id']);
                        if ($user_id != $image->user_id) {
                            if ($vehicle_data && $vehicle_data->created_at < $image->created_at) {
                                $image->moderator_gallery_changed = true;
                            }
                        }
                    }
                }
            }
        }
        unset($data['user']);
        $log = new ImageLog();
        $log->image_id = $image_id;
        $log->user_id = $user_id;
        $log->log = json_encode($data);
        $log->save();

        $image->save();

        $image = Image::where('id', $image_id)->select(['id', 'approved', 'created_at', 'published_at', 'description', 'description_alt', 'file', 'place', 'place_id', 'place2_id', 'place3_id', 'title', 'title_alt']);
        $image->addSelect(['my_approve' => function ($q) use ($user_id) {
            $q->selectRaw('count(id)')->from('image_approves')->where('moderator_id', $user_id)->whereRaw('image_id = images.id');
        }]);
        $image->where(function ($q) use ($user_id) {
            $q->where('user_id', '<>', $user_id)->orWhere('approved', '<>', 0);
        });
        return $image->first();
    }

    public function count($user_id, $type)
    {
        $images = Image::where(function ($q) use ($user_id) {
            $q->where('user_id', '<>', $user_id)->orWhere('approved', '<>', 0);
        });;
        switch ($type) {
            case 'rejected':
                $images->where('approved', -1);
                break;
            case 'approved':
                $images->where('approved', 1);
                break;
            case 'not-moderated':
                $images->where('approved', 0);
                break;
        }

        return $images->count();
    }

    public function updateClient($user_id, $image_id, $data)
    {
        try {

            $image = Image::find($image_id);
            if (!$image || $image->user_id != $user_id || $image->approved == -1) {
                return false;
            }
            $image->title = mb_substr($data['title'], 0, 240);
            $image->description = $data['description'];
            $image->title_alt = mb_substr($data['title_alt'], 0, 240);
            $image->description_alt = $data['description_alt'];
            if (isset($data['store_position']) && $data['store_position']) {
                $image->lat = $data['lat'];
                $image->lon = $data['lon'];
            } else {
                $image->lat = null;
                $image->lon = null;
            }

            $image->date = date('Y-m-d', strtotime($data['date']));

            $image->place = isset($data['place']['input']) ? $data['place']['input'] : null;

            $place_id = isset($data['place']['id']) ? $data['place']['id'] : null;
            if ($place_id) {
                $image->place_id = $place_id;
                $place = Place::find($place_id);
                if ($place && $place->parent_id) {
                    $place2_id = $place->parent_id;
                    if ($place2_id) {
                        $image->place2_id = $place2_id;
                        $place2 = Place::find($place2_id);
                        if ($place2 && $place2->parent_id) {
                            $place3_id = $place2->parent_id;
                            if ($place3_id) {
                                $image->place3_id = $place3_id;
                            }
                        }
                    }
                }
            }
            $image->save();

            $image_galleries = [];
            $image_vehicles = [];
            ImageGallery::where('image_id', $image_id)->delete();
            foreach ($data['galleries'] as $gallery) {
                if ($gallery['id']) {
                    $new_gallery = new ImageGallery();
                    $new_gallery->image_id = $image->id;
                    $new_gallery->gallery_id = $gallery['id'];
                    $new_gallery->save();
                    $image_galleries[] = $new_gallery;
                }
            }
            ImageVehicle::where('image_id', $image_id)->delete();
            foreach ($data['vehicles'] as $vehicle) {
                if ($vehicle['id'] || $vehicle['input']) {
                    $new_vehicle = new ImageVehicle();
                    $new_vehicle->image_id = $image->id;
                    $new_vehicle->vehicle_id = isset($vehicle['id']) ? $vehicle['id'] : null;
                    $new_vehicle->vehicle_text = isset($vehicle['input']) ? $vehicle['input'] : null;
                    $new_vehicle->save();
                    $image_vehicles[] = $new_vehicle;
                }
            }
            $image_json = json_encode([$image, $image_galleries, $image_vehicles]);
            $image_log = new ImageLog();
            $image_log->image_id = $image->id;
            $image_log->user_id = $user_id;
            $image_log->log = $image_json;
            $image_log->save();

        } catch (\Exception $e) {
            return false;
        }
        return $image->id;
    }

    public function store($user_id, $data)
    {
        try {
            $file = TmpUpload::where('user_id', $user_id)->where('id', $data['file_id'])->first();
            if (!$file) {
                return false;
            }
            $file_name = mb_ereg_replace('images_upload/tmp/', '', $file->path);
            rename(public_path($file->path), storage_path('app/public/images/' . $file_name));

            $image = new Image();
            $image->user_id = $user_id;
            $image->title = mb_substr($data['title'], 0, 240);
            $image->description = $data['description'];
            $image->title_alt = mb_substr($data['title_alt'], 0, 240);
            $image->description_alt = $data['description_alt'];
            if (isset($data['store_position']) && $data['store_position']) {
                $image->lat = $data['lat'];
                $image->lon = $data['lon'];
                $position = json_encode(['lat' => $data['lat'], 'lon' => $data['lon']]);
                UserMeta::where('id', $user_id)->update(['last_position' => $position]);
            }
            $image->date = date('Y-m-d', strtotime($data['date']));

            $image->file = $file_name;
            $image->width = $file->width;
            $image->height = $file->height;
            $image->vertical = $file->height > $file->width;
            $image->place = isset($data['place']['input']) ? $data['place']['input'] : null;

            $place_id = isset($data['place']['id']) ? $data['place']['id'] : null;
            if ($place_id) {
                $image->place_id = $place_id;
                $place = Place::find($place_id);
                if ($place && $place->parent_id) {
                    $place2_id = $place->parent_id;
                    if ($place2_id) {
                        $image->place2_id = $place2_id;
                        $place2 = Place::find($place2_id);
                        if ($place2 && $place2->parent_id) {
                            $place3_id = $place2->parent_id;
                            if ($place3_id) {
                                $image->place3_id = $place3_id;
                            }
                        }
                    }
                }
            }
            $image->save();
            $image_raw = IImage::make(storage_path('app/public/images/' . $file_name))->orientate();
            $exif = $image_raw->exif();

            foreach ($exif as $key => $ex) {
                if (!is_array($ex) && isset($this->exif_fields[mb_strtolower($key)])) {
                    $value = $this->exif_fields[mb_strtolower($key)] ? eval('return ' . $ex . ';') : $ex;
                    if ($value) {
                        $exf = new ImageExif();
                        $exf->image_id = $image->id;
                        $exf->name = $key;
                        $exf->value = $value;
                        $exf->save();
                    }
                }
            }

            $image_galleries = [];
            $image_vehicles = [];
            foreach ($data['galleries'] as $gallery) {
                if ($gallery['id']) {
                    $new_gallery = new ImageGallery();
                    $new_gallery->image_id = $image->id;
                    $new_gallery->gallery_id = $gallery['id'];
                    $new_gallery->save();
                    $image_galleries[] = $new_gallery;
                }
            }
            foreach ($data['vehicles'] as $vehicle) {
                if ($vehicle['id'] || $vehicle['input']) {
                    $new_vehicle = new ImageVehicle();
                    $new_vehicle->image_id = $image->id;
                    $new_vehicle->vehicle_id = isset($vehicle['id']) ? $vehicle['id'] : null;
                    $new_vehicle->vehicle_text = isset($vehicle['input']) ? $vehicle['input'] : null;
                    $new_vehicle->save();
                    $image_vehicles[] = $new_vehicle;
                }
            }
            $image_json = json_encode([$image, $image_galleries, $image_vehicles]);
            $image_log = new ImageLog();
            $image_log->image_id = $image->id;
            $image_log->user_id = $user_id;
            $image_log->log = $image_json;
            $image_log->save();
            $width = $image->width;
            $height = $image->height;
            if ($width > $height) {
                $new_height = 370;
                $new_width = $width / ($height / 370);
            } else {
                $new_width = 370;
                $new_height = $height / ($width / 370);
            }
            $image_raw->resize($new_width, $new_height);
            $image_raw->save(storage_path('app/public/images/thumbs/' . $file_name), 60, 'jpg');
            $file->delete();
        } catch (\Exception $e) {
            return false;
        }
        return $file_name;
    }

    public function storeComment($image_id, $user_id, $comment_text)
    {
        $comment = new ImageComment();
        $comment->user_id = $user_id;
        $comment->image_id = $image_id;
        $comment->comment = MessageStr::convertString($comment_text);
        $comment->save();

        $image = Image::where('id', $image_id)->first();

        $comment_text = mb_ereg_replace('\<.*?\>', ' ', $comment_text);
        $comment_text = mb_ereg_replace('\s\s', ' ', $comment_text);
        $words = explode(' ', trim($comment_text));
        $comment_min_words = Config::get('site.comment_min_words', 1);

        $now = now()->timestamp;
        $image_timestamp = $image->published_at ? $image->published_at : 0;
        $image_days = ($now - $image_timestamp) / 60 / 60 / 24;
        $image_1 = $image_days <= 1;
        $image_2 = $image_days <= 2;
        $image_3 = $image_days <= 3;
        $image_7 = $image_days <= 7;

        $stat = ImageStat::where('image_id', $image_id)->first();
        if (!$stat) {
            $stat = new ImageStat();
            $stat->image_id = $image->id;
        }
        $stat->comments_all = $stat->comments_all + 1;
        $stat->comments_all_hour = $stat->comments_all_hour + 1;

        $stat->comments_all_1 = $stat->comments_all_1 + (int)$image_1;
        $stat->comments_all_2 = $stat->comments_all_2 + (int)$image_2;
        $stat->comments_all_3 = $stat->comments_all_3 + (int)$image_3;
        $stat->comments_all_7 = $stat->comments_all_7 + (int)$image_7;

        if (count($words) >= $comment_min_words) {
            $stat->comments_1 = $stat->comments_1 + (int)$image_1;
            $stat->comments_2 = $stat->comments_2 + (int)$image_2;
            $stat->comments_3 = $stat->comments_3 + (int)$image_3;
            $stat->comments_7 = $stat->comments_7 + (int)$image_7;
            $stat->comments = $stat->comments + 1;
            $stat->comments_hour = $stat->comments_hour + 1;
        }
        $stat->save();
        return $comment;
    }

    public function getComment($id)
    {
        $comment = ImageComment::find($id);
        return $comment;
    }

    public function getCommentsClient($image_id)
    {
        $comments = ImageComment::orderBy('created_at', 'ASC');
        $comments->where('image_id', $image_id);
        $comments->select(['image_comments.id', 'image_comments.user_id', 'created_at', 'comment', 'user_metas.name']);

        $comments->leftJoin('user_metas', 'image_comments.user_id', '=', 'user_metas.user_id');

        return $comments->get();
    }

    public function getRejectsClient($image_id, $user_id)
    {
        $image = Image::where('id', $image_id)->where('user_id', $user_id)->first();
        if (!$image) {
            return false;
        }
        $rejects = ImageApprove::select(['approved', 'comment'])->where('image_id', $image_id)->orderBy('created_at', 'ASC');

        return $rejects->get();
    }

    public function setView($user, $image)
    {
        $stat = ImageStat::where('image_id', $image->id)->first();
        $now = now()->timestamp;

        $image_timestamp = $image->published_at ? $image->published_at : 0;
        $image_days = ($now - $image_timestamp) / 60 / 60 / 24;
        $image_1 = $image_days <= 1;
        $image_2 = $image_days <= 2;
        $image_3 = $image_days <= 3;
        $image_7 = $image_days <= 7;

        if (!$stat) {
            $stat = new ImageStat();
            $stat->image_id = $image->id;
        }
        $stat->views_all = $stat->views_all + 1;
        $stat->views_all_1 = $stat->views_all_1 + (int)$image_1;
        $stat->views_all_2 = $stat->views_all_2 + (int)$image_2;
        $stat->views_all_3 = $stat->views_all_3 + (int)$image_3;
        $stat->views_all_7 = $stat->views_all_7 + (int)$image_7;
        $stat->views_all_hour = $stat->views_all_hour + 1;

        if ($user) {
            $view = ImageView::where(['user_id' => $user->id, 'image_id' => $image->id])->first();

            if (!$view) {

                $view = new ImageView();
                $view->user_id = $user->id;
                $view->image_id = $image->id;
                $view->save();

                $stat->views_hour = $stat->views_hour + 1;
                $stat->views = $stat->views + 1;
                $stat->views_1 = $stat->views_1 + (int)$image_1;
                $stat->views_2 = $stat->views_2 + (int)$image_2;
                $stat->views_3 = $stat->views_3 + (int)$image_3;
                $stat->views_7 = $stat->views_7 + (int)$image_7;
                $image->view_count = $image->view_count + 1;
                $image->save();
            }
            $session_data = request()->session_data ?? [];
            $views = $session_data['images_viewed'] ?? [];
            $views[] = $image->id;
            $session_data['images_viewed'] = $views;
            UserSession::where('session_id', request()->session_id)->update(['data' => $session_data]);
        } else {
            $session_data = request()->session_data ?? [];
            $views = $session_data['images_viewed'] ?? [];

            $anonym_view = in_array($image->id, $views);

            $views[] = $image->id;
            $session_data['images_viewed'] = $views;
            UserSession::where('session_id', request()->session_id)->update(['data' => $session_data]);
            if (!$anonym_view) {
                $stat->views_anonym_hour = $stat->views_anonym_hour + 1;
                $stat->views_anonym = $stat->views_anonym + 1;
                $stat->views_anonym_1 = $stat->views_anonym_1 + (int)$image_1;
                $stat->views_anonym_2 = $stat->views_anonym_2 + (int)$image_2;
                $stat->views_anonym_3 = $stat->views_anonym_3 + (int)$image_3;
                $stat->views_anonym_7 = $stat->views_anonym_7 + (int)$image_7;
                $image->view_count = $image->view_count + 1;
                $image->save();
            }
        }
        $stat->save();
        return $image;
    }

    public function delete($image_id, $user_id)
    {
        $image = Image::where('id', $image_id)->where('approved', '<>', 1)->first();
        if (!$image || $image->user_id != $user_id) {
            return false;
        }
        try {
            unlink(storage_path('app/public/images/' . $image->file));
            unlink(storage_path('app/public/images/thumbs/' . $image->file));
        } catch (\Exception $e) {
        }
        $image->delete();
        return true;
    }

    public function setVisible($image_id, $user_id, $type)
    {
        $image = Image::where('id', $image_id)->where('approved', 1)->first();
        if (!$image || $image->user_id != $user_id) {
            return false;
        }
        $image->visible = $type == 'show' ? 1 : 0;
        $image->save();
        return true;
    }

    public function setFavorite($image_id, $user_id)
    {
        $image = Image::find($image_id);
        if (!$image || $image->user_id == $user_id) {
            return false;
        }

        $now = now()->timestamp;
        $image_timestamp = $image->published_at ? $image->published_at : 0;
        $image_days = ($now - $image_timestamp) / 60 / 60 / 24;
        $image_1 = $image_days <= 1;
        $image_2 = $image_days <= 2;
        $image_3 = $image_days <= 3;
        $image_7 = $image_days <= 7;

        $stat = ImageStat::where('image_id', $image_id)->first();
        if (!$stat) {
            $stat = new ImageStat();
            $stat->image_id = $image->id;
        }

        $favorite = ImageFavorite::where(['user_id' => $user_id, 'image_id' => $image_id])->first();
        if ($favorite) {
            $favorite->delete();
            UserMeta::where('user_id', $image->user_id)->update(['favorites_count' => DB::raw('favorites_count - 1')]);
            $correction = -1;
        } else {
            $favorite = new ImageFavorite();
            $favorite->user_id = $user_id;
            $favorite->image_id = $image_id;
            $favorite->save();
            UserMeta::where('user_id', $image->user_id)->update(['favorites_count' => DB::raw('favorites_count + 1')]);
            $correction = 1;
        }
        $stat->favorites = $stat->favorites + $correction;
        $stat->favorites_hour = $stat->favorites_hour + $correction;

        $stat->favorites_1 = $stat->favorites_1 + (int)$image_1 * $correction;
        $stat->favorites_2 = $stat->favorites_2 + (int)$image_2 * $correction;
        $stat->favorites_3 = $stat->favorites_3 + (int)$image_3 * $correction;
        $stat->favorites_7 = $stat->favorites_7 + (int)$image_7 * $correction;
        $stat->save();
        return true;
    }

    public function setLike($image_id, $user_id, $type)
    {
        $image = Image::find($image_id);
        if (!$image || $image->user_id == $user_id) {
            return false;
        }
        $stat = ImageStat::where('image_id', $image_id)->first();
        if (!$stat) {
            $stat = new ImageStat();
            $stat->image_id = $image->id;
        }
        $now = now()->timestamp;
        $image_timestamp = $image->published_at?$image->published_at:0;
        $image_days = ($now - $image_timestamp) / 60 / 60 / 24;
        $image_1 = $image_days <= 1;
        $image_2 = $image_days <= 2;
        $image_3 = $image_days <= 3;
        $image_7 = $image_days <= 7;

        if ($user_id) {

            $like = ImageLike::where(['user_id' => $user_id, 'image_id' => $image_id])->first();
            if (!$like) {

                $like = new ImageLike();
                $like->user_id = $user_id;
                $like->image_id = $image_id;
                $like->like = $type == 'like' ? 1 : -1;

                if ($type == 'like') {
                    $like->like = 1;
                    $stat->likes_hour = $stat->likes_hour + 1;
                    $stat->likes = $stat->likes + 1;
                    $stat->likes_1 = $stat->likes_1 + (int)$image_1;
                    $stat->likes_2 = $stat->likes_2 + (int)$image_2;
                    $stat->likes_3 = $stat->likes_3 + (int)$image_3;
                    $stat->likes_7 = $stat->likes_7 + (int)$image_7;
                    $image->like_count = $image->like_count + 1;
                    UserMeta::where('user_id', $image->user_id)->update(['likes_count' => DB::raw('likes_count + 1')]);
                } else {
                    $like->like = -1;
                    $stat->dislikes_hour = $stat->dislikes_hour + 1;
                    $stat->dislikes = $stat->dislikes + 1;
                    $stat->dislikes_1 = $stat->dislikes_1 + (int)$image_1;
                    $stat->dislikes_2 = $stat->dislikes_2 + (int)$image_2;
                    $stat->dislikes_3 = $stat->dislikes_3 + (int)$image_3;
                    $stat->dislikes_7 = $stat->dislikes_7 + (int)$image_7;
                    $image->dislike_count = $image->dislike_count + 1;
                    UserMeta::where('user_id', $image->user_id)->update(['dislikes_count' => DB::raw('dislikes_count + 1')]);
                }

                $like->save();
                $image->save();
                $stat->save();
                return true;
            }
            return false;
        } else {
            $session_data = request()->session_data ?? [];
            $likes = $session_data['images_likes'] ?? [];

            if (!isset($likes[$image_id])) {

                $likes[$image_id] = $type == 'like' ? 1 : -1;
                $session_data['images_likes'] = $likes;
                UserSession::where('session_id', request()->session_id)->update(['data' => $session_data]);

                if ($type == 'like') {

                    $stat->likes_anonym_hour = $stat->likes_anonym_hour + 1;
                    $stat->likes_anonym = $stat->likes_anonym + 1;
                    $stat->likes_anonym_1 = $stat->likes_anonym_1 + (int)$image_1;
                    $stat->likes_anonym_2 = $stat->likes_anonym_2 + (int)$image_2;
                    $stat->likes_anonym_3 = $stat->likes_anonym_3 + (int)$image_3;
                    $stat->likes_anonym_7 = $stat->likes_anonym_7 + (int)$image_7;
                    $image->like_count = $image->like_count + 1;
                    UserMeta::where('user_id', $image->user_id)->update(['likes_count' => DB::raw('likes_count + 1')]);
                } else {

                    $stat->dislikes_anonym_hour = $stat->dislikes_anonym_hour + 1;
                    $stat->dislikes_anonym = $stat->dislikes_anonym + 1;
                    $stat->dislikes_anonym_1 = $stat->dislikes_anonym_1 + (int)$image_1;
                    $stat->dislikes_anonym_2 = $stat->dislikes_anonym_2 + (int)$image_2;
                    $stat->dislikes_anonym_3 = $stat->dislikes_anonym_3 + (int)$image_3;
                    $stat->dislikes_anonym_7 = $stat->dislikes_anonym_7 + (int)$image_7;
                    $image->dislike_count = $image->dislike_count + 1;
                    UserMeta::where('user_id', $image->user_id)->update(['dislikes_count' => DB::raw('dislikes_count + 1')]);
                }

                $image->save();
                $stat->save();
                return true;
            }
            return false;
        }
    }

    public function getTitle($filters, $language_id)
    {
        $default_language = 1;
        $result = true;
        if (isset($filters['vehicle_id'])) {
            $vehicle = Vehicle::select([DB::raw('vehicles.id as id, IFNULL(vh2.name, vehicle_translations.name) as name'), 'model_id' => 'model_id'])->
            leftJoin('vehicle_translations', function($q) use ($default_language) {
                $q->on('vehicles.id', '=', 'vehicle_translations.vehicle_id')
                    ->where('vehicle_translations.language_id', '=', $default_language);
            })->

            leftJoin('vehicle_translations as vh2', function($q) use ($language_id) {
                $q->on('vehicle_translations.vehicle_id', '=', 'vh2.vehicle_id')
                    ->where('vh2.language_id', '=', $language_id);
            })->
            where('vehicle_translations.vehicle_id', $filters['vehicle_id'])->first();
            $banner_path = public_path('');
            $result = $vehicle?['title' => $vehicle->name, 'banner' => '/banners/models/' . $vehicle->model_id . '.jpg']:false;
        }
        if (isset($filters['gallery_id'])) {
            $gallery = Gallery::select(DB::raw('galleries.id as id, IFNULL(g2.name, gallery_translations.name) as name'))->
            leftJoin('gallery_translations', function($q) use ($default_language) {
                $q->on('galleries.id', '=', 'gallery_translations.gallery_id')
                    ->where('gallery_translations.language_id', '=', $default_language);
            })->

            leftJoin('gallery_translations as g2', function($q) use ($language_id) {
                $q->on('gallery_translations.gallery_id', '=', 'g2.gallery_id')
                    ->where('g2.language_id', '=', $language_id);
            })->
            where('gallery_translations.gallery_id', $filters['gallery_id'])->first();
            $result = $gallery?['title' => $gallery->name, 'banner' => false]:false;
        }
        if (isset($filters['place_id'])) {
            $place = Place::select(DB::raw('places.id as id, IFNULL(p2.full_name, place_translations.full_name) as name'))->
            leftJoin('place_translations', function($q) use ($default_language) {
                $q->on('places.id', '=', 'place_translations.place_id')
                    ->where('place_translations.language_id', '=', $default_language);
            })->

            leftJoin('place_translations as p2', function($q) use ($language_id) {
                $q->on('place_translations.place_id', '=', 'p2.place_id')
                    ->where('p2.language_id', '=', $language_id);
            })->
            where('place_translations.place_id', $filters['place_id'])->first();
            $result = $place?['title' => $place->name, 'banner' => false]:false;
        }
        if (isset($filters['user_id'])) {
            $user = User::where('id', $filters['user_id'])->where('status', 1)->whereNull('deleted_at')->with(['meta', 'score'])->first();
            $images_count = Image::where('user_id', $filters['user_id'])->where('approved', 1)->where('visible', 1)->count();
            $banner_path = storage_path('app/public/banners/');
            $result = $user?['name' => $user->meta->name, 'banner' => $user->meta->banner, 'avatar' => $user->meta->avatar, 'images_count' => $images_count, 'score'=>$user->score?$user->score->score:0]:false;
        }
        if ($result===true) {
            return $result;
        }
        $result['banner'] = $result['banner']?(file_exists($banner_path . $result['banner'])?$result['banner']:false):false;
        return $result;
    }

    public function getImageWeb($id)
    {
        return Image::find($id);
    }

}
