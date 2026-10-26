using Microsoft.UI.Xaml.Media.Animation;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private readonly Dictionary<Storyboard, TaskCompletionSource<bool>> _collectionAnimations = [];

    private async Task<bool> PlayCollectionAnimationAsync(Storyboard animation)
    {
        if (_disposed || !_visible || !IsLoaded) return false;
        var completed = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        _collectionAnimations.Add(animation, completed);
        void OnCompleted(object? sender, object args) => completed.TrySetResult(true);
        animation.Completed += OnCompleted;
        try
        {
            animation.Begin();
            return await completed.Task;
        }
        finally
        {
            animation.Completed -= OnCompleted;
            _collectionAnimations.Remove(animation);
        }
    }

    private void CancelCollectionAnimations()
    {
        foreach ((Storyboard animation, TaskCompletionSource<bool> completed) in _collectionAnimations.ToArray())
        {
            animation.Stop();
            completed.TrySetResult(false);
        }
        _collectionAnimations.Clear();
    }
}
