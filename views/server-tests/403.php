<?php 
$this->layout('layout', [
                'title' => $test->name,
              ]);

$this->insert('partials/update-test-basic', [
  'test' => $test,
  'endpoint' => $endpoint,
  'description' => 'This test will create a post with two categories, then attempt to delete one of the categories.',
  'feature_num' => 18,
  'postbody' => '{
  "type": ["h-entry"],
  "properties": {
    "content": ["This test deletes a category from the post. After you run the update, this post should have only the category test1."],
    "category": ["test1", "test2"]
  }
}',
  'updatebody' => '{
  "action": "update",
  "url": "%%%",
  "delete": {
    "category": ["test2"]
  }
}',
]);
