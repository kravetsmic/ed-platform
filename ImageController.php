<?php

namespace App\Http\Controllers\Image;

use App\Http\Controllers\Controller;
use App\Services\Image\ImageService;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    protected $service;

    public function __construct(ImageService $service)
    {
        $this->service = $service;
    }

    public function getList(Request $request)
    {
        $user_id = $request->user->id;
        $images = $this->service->list($user_id, $request->page, $request->type);
        $count = $this->service->count($user_id, $request->type);
        return response()->json(['images' => ['images' => $images, 'count' => $count]]);
    }

    public function approve(Request $request, $image_id)
    {
        $user_id = $request->user->id;
        $approve = $request->approve;
        $comment = $request->comment;
        $approved = $this->service->approve($user_id, $image_id, $approve, $comment);
        if (!$approved) {
            abort(400);
        }
        return response()->json([]);
    }

    public function update(Request $request, $image_id)
    {
        $user_id = $request->user->id;
        $update = $this->service->update($user_id, $image_id, $request->all());
        if (!$update) {
            abort(400);
        }
        return response()->json(['image' => $update]);
    }

    public function store(Request $request)
    {
        $user_id = $request->user->id;
        $image = $this->service->store($user_id, $request->image);
        if (!$image) {
            abort(500, 'Upload error');
        }
        return response()->json($image);
    }

    public function updateClient(Request $request, $id)
    {
        $user_id = $request->user->id;
        $image = $this->service->updateClient($user_id, $id, $request->image);
        if (!$image) {
            abort(500, 'Update error');
        }
        return response()->json($image);
    }

    public function storeComment($id, Request $request)
    {
        $user_id = $request->user->id;
        $comment = $this->service->storeComment($id, $user_id, $request->comment);
        if (!$comment) {
            abort(500, 'Store comment error');
        }
        return response()->json(['comment' => $comment]);
    }

    public function deleteComment($id, Request $request)
    {
        $user_id = $request->user->id;
        $comment = $this->service->getComment($id);
        if (!$comment) {
            abort(400, 'Comment not found');
        }

        if ($comment->user_id!=$user_id && !in_array('comment.moderate', $request->user->permissions->toArray())) {
            abort(400, 'Not allowed');
        }

        $this->service->deleteComment($comment);

        return response()->json([]);
    }

    public function like($id, $type, Request $request)
    {
        $user_id = $request->user?$request->user->id:0;
        $like = $this->service->setLike($id, $user_id, $type);

        return response()->json(['result' => $like?'ok':false]);
    }

    public function favorite($id, Request $request)
    {
        $user_id = $request->user->id;
        $favorite = $this->service->setFavorite($id, $user_id);

        return response()->json(['result' => $favorite?'ok':false]);
    }

    public function visible($id, $type, Request $request)
    {
        $user_id = $request->user->id;
        $visible = $this->service->setVisible($id, $user_id, $type);

        return response()->json(['result' => $visible?'ok':false]);
    }

    public function delete($id, Request $request)
    {
        $user_id = $request->user->id;
        $delete = $this->service->delete($id, $user_id);

        return response()->json(['result' => $delete?'ok':false]);
    }
}
