<?php

use App\Enums\PrintJobStatus;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\PdfPrintJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('HMS Drive')] class extends Component
{
    use WithFileUploads;

    #[Url(as: 'folder')]
    public ?int $currentFolderId = null;

    public string $search = '';

    public bool $showFolderModal = false;

    public ?int $editingFolderId = null;

    public string $folderName = '';

    public bool $showUploadModal = false;

    public ?TemporaryUploadedFile $upload = null;

    public string $fileName = '';

    public string $fileTags = '';

    public bool $showEditFileModal = false;

    public ?int $editingFileId = null;

    public string $editFileName = '';

    public string $editFileTags = '';

    public bool $showViewModal = false;

    public ?int $viewingFileId = null;

    public bool $showPrintModal = false;

    public ?int $printingFileId = null;

    public int $copies = 1;

    /**
     * Restrict the page to admin and management users.
     */
    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null || (! $user->isAdmin() && ! $user->isManagement())) {
            abort(403);
        }

        if ($this->currentFolderId !== null && DriveFolder::find($this->currentFolderId) === null) {
            $this->currentFolderId = null;
        }
    }

    /**
     * Open a folder.
     */
    public function openFolder(int $folderId): void
    {
        $this->search = '';
        $this->currentFolderId = $folderId;
    }

    /**
     * Navigate to the drive root.
     */
    public function goToRoot(): void
    {
        $this->search = '';
        $this->currentFolderId = null;
    }

    /**
     * Open the create-folder modal.
     */
    public function openCreateFolderModal(): void
    {
        $this->editingFolderId = null;
        $this->folderName = '';
        $this->showFolderModal = true;
        $this->resetValidation();
    }

    /**
     * Open the rename-folder modal.
     */
    public function openRenameFolderModal(int $folderId): void
    {
        $folder = DriveFolder::findOrFail($folderId);

        $this->editingFolderId = $folder->id;
        $this->folderName = $folder->name;
        $this->showFolderModal = true;
        $this->resetValidation();
    }

    /**
     * Create or rename a folder.
     */
    public function saveFolder(): void
    {
        $parentId = $this->currentFolderId;

        if ($this->editingFolderId !== null) {
            $folder = DriveFolder::findOrFail($this->editingFolderId);
            $parentId = $folder->parent_id;
        }

        $this->validate([
            'folderName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('drive_folders', 'name')
                    ->where(fn (QueryBuilder $query) => $parentId === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $parentId))
                    ->ignore($this->editingFolderId),
            ],
        ]);

        if ($this->editingFolderId !== null) {
            DriveFolder::findOrFail($this->editingFolderId)->update([
                'name' => $this->folderName,
            ]);

            session()->flash('status', __('Folder renamed.'));
        } else {
            DriveFolder::create([
                'parent_id' => $this->currentFolderId,
                'name' => $this->folderName,
                'created_by' => Auth::id(),
            ]);

            session()->flash('status', __('Folder created.'));
        }

        $this->showFolderModal = false;
        $this->folderName = '';
        $this->editingFolderId = null;

        unset($this->folders, $this->breadcrumbs);
    }

    /**
     * Confirm and delete a folder with its contents.
     */
    public function deleteFolder(int $folderId): void
    {
        $folder = DriveFolder::with(['children', 'files'])->findOrFail($folderId);
        $parentId = $folder->parent_id;
        $shouldNavigateUp = false;

        if ($this->currentFolderId === $folderId) {
            $shouldNavigateUp = true;
        } elseif ($this->currentFolderId !== null) {
            $current = DriveFolder::find($this->currentFolderId);

            if ($current !== null) {
                foreach ($current->breadcrumb() as $crumb) {
                    if ($crumb->id === $folderId) {
                        $shouldNavigateUp = true;
                        break;
                    }
                }
            }
        }

        $folder->deleteWithContents();

        if ($shouldNavigateUp) {
            $this->currentFolderId = $parentId;
        }

        unset($this->folders, $this->files, $this->breadcrumbs, $this->currentFolder);

        session()->flash('status', __('Folder deleted.'));
    }

    /**
     * Open the upload modal.
     */
    public function openUploadModal(): void
    {
        $this->reset('upload', 'fileName', 'fileTags');
        $this->showUploadModal = true;
        $this->resetValidation();
    }

    /**
     * Prefill the display name when a file is selected.
     */
    public function updatedUpload(): void
    {
        if ($this->upload instanceof TemporaryUploadedFile && blank($this->fileName)) {
            $this->fileName = pathinfo($this->upload->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    /**
     * Upload a file into the current folder.
     */
    public function uploadFile(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
            'fileName' => ['required', 'string', 'max:255'],
            'fileTags' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var TemporaryUploadedFile $upload */
        $upload = $this->upload;

        $directory = $this->currentFolderId === null
            ? 'hms-drive/root'
            : 'hms-drive/'.$this->currentFolderId;

        $path = $upload->store($directory, 'local');

        DriveFile::create([
            'folder_id' => $this->currentFolderId,
            'name' => $this->fileName,
            'original_filename' => $upload->getClientOriginalName(),
            'disk_path' => $path,
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize() ?: 0,
            'tags' => $this->parsedTags($this->fileTags),
            'created_by' => Auth::id(),
        ]);

        $this->showUploadModal = false;
        $this->reset('upload', 'fileName', 'fileTags');

        unset($this->files);

        session()->flash('status', __('File uploaded.'));
    }

    /**
     * Open the edit-file modal.
     */
    public function openEditFileModal(int $fileId): void
    {
        $file = DriveFile::findOrFail($fileId);

        $this->editingFileId = $file->id;
        $this->editFileName = $file->name;
        $this->editFileTags = is_array($file->tags) ? implode(', ', $file->tags) : '';
        $this->showEditFileModal = true;
        $this->resetValidation();
    }

    /**
     * Update a file's display name and tags.
     */
    public function saveFile(): void
    {
        $this->validate([
            'editFileName' => ['required', 'string', 'max:255'],
            'editFileTags' => ['nullable', 'string', 'max:500'],
        ]);

        $file = DriveFile::findOrFail($this->editingFileId);

        $file->update([
            'name' => $this->editFileName,
            'tags' => $this->parsedTags($this->editFileTags),
        ]);

        $this->showEditFileModal = false;
        $this->editingFileId = null;

        unset($this->files);

        session()->flash('status', __('File updated.'));
    }

    /**
     * Delete a file and its storage object.
     */
    public function deleteFile(int $fileId): void
    {
        $file = DriveFile::findOrFail($fileId);
        $file->deleteWithStorage();

        if ($this->viewingFileId === $fileId) {
            $this->showViewModal = false;
            $this->viewingFileId = null;
        }

        unset($this->files);

        session()->flash('status', __('File deleted.'));
    }

    /**
     * Open the image viewer modal.
     */
    public function viewImage(int $fileId): void
    {
        $file = DriveFile::findOrFail($fileId);

        if (! $file->isImage()) {
            return;
        }

        $this->viewingFileId = $file->id;
        $this->showViewModal = true;
    }

    /**
     * Open the print modal for a PDF.
     */
    public function openPrintModal(int $fileId): void
    {
        $file = DriveFile::findOrFail($fileId);

        if (! $file->isPdf()) {
            session()->flash('error', __('Only PDF files can be printed.'));

            return;
        }

        $this->printingFileId = $file->id;
        $this->copies = 1;
        $this->showPrintModal = true;
        $this->resetValidation();
    }

    /**
     * Queue a drive PDF for printing via the existing print agent flow.
     */
    public function queuePrint(): void
    {
        $this->validate([
            'copies' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $file = DriveFile::findOrFail($this->printingFileId);

        if (! $file->isPdf()) {
            session()->flash('error', __('Only PDF files can be printed.'));

            return;
        }

        if (! Storage::disk('local')->exists($file->disk_path)) {
            session()->flash('error', __('File is missing and cannot be printed.'));

            return;
        }

        $path = 'pdf-print-jobs/'.Str::uuid().'.pdf';
        Storage::disk('local')->copy($file->disk_path, $path);

        $printName = str_ends_with(strtolower($file->name), '.pdf')
            ? $file->name
            : $file->name.'.pdf';

        PdfPrintJob::create([
            'user_id' => Auth::id(),
            'original_filename' => $printName,
            'disk_path' => $path,
            'copies' => $this->copies,
            'status' => PrintJobStatus::Pending,
        ]);

        $this->showPrintModal = false;
        $this->printingFileId = null;
        $this->copies = 1;

        session()->flash('status', __('PDF queued for printing.'));
    }

    /**
     * Get the current folder model.
     */
    #[Computed]
    public function currentFolder(): ?DriveFolder
    {
        if ($this->currentFolderId === null) {
            return null;
        }

        return DriveFolder::with('parent')->find($this->currentFolderId);
    }

    /**
     * Get the breadcrumb trail for the current folder.
     *
     * @return list<DriveFolder>
     */
    #[Computed]
    public function breadcrumbs(): array
    {
        return $this->currentFolder?->breadcrumb() ?? [];
    }

    /**
     * Get folders for the current view.
     *
     * @return Collection<int, DriveFolder>
     */
    #[Computed]
    public function folders(): Collection
    {
        if (filled($this->search)) {
            return new Collection;
        }

        return DriveFolder::query()
            ->with('creator')
            ->when(
                $this->currentFolderId === null,
                fn (Builder $query) => $query->whereNull('parent_id'),
                fn (Builder $query) => $query->where('parent_id', $this->currentFolderId),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Get files for the current view or search results.
     *
     * @return Collection<int, DriveFile>
     */
    #[Computed]
    public function files(): Collection
    {
        $query = DriveFile::query()->with(['creator', 'folder']);

        if (filled($this->search)) {
            return $query->search($this->search)->latest()->limit(100)->get();
        }

        return $query
            ->when(
                $this->currentFolderId === null,
                fn (Builder $q) => $q->whereNull('folder_id'),
                fn (Builder $q) => $q->where('folder_id', $this->currentFolderId),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the file being viewed in the modal.
     */
    #[Computed]
    public function viewingFile(): ?DriveFile
    {
        if ($this->viewingFileId === null) {
            return null;
        }

        return DriveFile::find($this->viewingFileId);
    }

    /**
     * Parse comma-separated tags into a unique list.
     *
     * @return list<string>
     */
    private function parsedTags(string $tags): array
    {
        return collect(explode(',', $tags))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Format a byte size for display.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('HMS Drive') }}</flux:heading>

            <div class="flex flex-wrap gap-2">
                <flux:button variant="ghost" icon="folder-plus" wire:click="openCreateFolderModal">
                    {{ __('New folder') }}
                </flux:button>
                <flux:button variant="primary" icon="arrow-up-tray" wire:click="openUploadModal">
                    {{ __('Upload') }}
                </flux:button>
            </div>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle">
                {{ session('status') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle">
                {{ session('error') }}
            </flux:callout>
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item wire:click="goToRoot" class="cursor-pointer">{{ __('Drive') }}</flux:breadcrumbs.item>
                @foreach ($this->breadcrumbs as $crumb)
                    <flux:breadcrumbs.item wire:click="openFolder({{ $crumb->id }})" class="cursor-pointer">
                        {{ $crumb->name }}
                    </flux:breadcrumbs.item>
                @endforeach
            </flux:breadcrumbs>

            <div class="w-full sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    placeholder="{{ __('Search name or tags...') }}"
                    clearable
                />
            </div>
        </div>

        <flux:card>
            @if (filled($this->search))
                <flux:heading level="2" class="mb-4">{{ __('Search results') }}</flux:heading>
            @endif

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Tags') }}</flux:table.column>
                    <flux:table.column>{{ __('Size') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded by') }}</flux:table.column>
                    <flux:table.column>{{ __('Updated') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->folders as $folder)
                        <flux:table.row wire:key="drive-folder-{{ $folder->id }}">
                            <flux:table.cell>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 font-medium text-zinc-900 hover:underline dark:text-zinc-100"
                                    wire:click="openFolder({{ $folder->id }})"
                                >
                                    <flux:icon name="folder" class="size-4 text-amber-500" />
                                    {{ $folder->name }}
                                </button>
                            </flux:table.cell>
                            <flux:table.cell>{{ __('Folder') }}</flux:table.cell>
                            <flux:table.cell><span class="text-zinc-400">-</span></flux:table.cell>
                            <flux:table.cell><span class="text-zinc-400">-</span></flux:table.cell>
                            <flux:table.cell>{{ $folder->creator?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $folder->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="inline-flex gap-1">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        wire:click="openRenameFolderModal({{ $folder->id }})"
                                    />
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="deleteFolder({{ $folder->id }})"
                                        wire:confirm="{{ __('Delete this folder and all its contents?') }}"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        @if (blank($this->search) && $this->files->isEmpty())
                            <flux:table.row>
                                <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                    {{ __('This folder is empty.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @endforelse

                    @foreach ($this->files as $file)
                        <flux:table.row wire:key="drive-file-{{ $file->id }}">
                            <flux:table.cell>
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex items-center gap-2 font-medium">
                                        <flux:icon
                                            name="{{ $file->isPdf() ? 'document-text' : 'photo' }}"
                                            class="size-4 {{ $file->isPdf() ? 'text-red-500' : 'text-sky-500' }}"
                                        />
                                        {{ $file->name }}
                                    </span>
                                    @if (filled($this->search) && $file->folder)
                                        <span class="text-xs text-zinc-400">{{ __('in :folder', ['folder' => $file->folder->name]) }}</span>
                                    @elseif (filled($this->search))
                                        <span class="text-xs text-zinc-400">{{ __('in Drive root') }}</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $file->isPdf() ? __('PDF') : __('Image') }}</flux:table.cell>
                            <flux:table.cell>
                                @if (is_array($file->tags) && count($file->tags) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($file->tags as $tag)
                                            <flux:badge size="sm" color="zinc" variant="ghost">{{ $tag }}</flux:badge>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $this->formatBytes($file->size) }}</flux:table.cell>
                            <flux:table.cell>{{ $file->creator?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $file->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1">
                                    @if ($file->isImage())
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="eye"
                                            wire:click="viewImage({{ $file->id }})"
                                        />
                                    @else
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="eye"
                                            :href="route('admin.drive.view', $file)"
                                            target="_blank"
                                        />
                                    @endif
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-down-tray"
                                        :href="route('admin.drive.download', $file)"
                                    />
                                    @if ($file->isPdf())
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="printer"
                                            wire:click="openPrintModal({{ $file->id }})"
                                        />
                                    @endif
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        wire:click="openEditFileModal({{ $file->id }})"
                                    />
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="deleteFile({{ $file->id }})"
                                        wire:confirm="{{ __('Delete this file?') }}"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    @if (filled($this->search) && $this->files->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                {{ __('No files match your search.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showFolderModal" class="w-full max-w-md">
        <form wire:submit="saveFolder" class="space-y-4">
            <flux:heading level="2">
                {{ $editingFolderId === null ? __('New folder') : __('Rename folder') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Folder name') }}</flux:label>
                <flux:input wire:model="folderName" />
                <flux:error name="folderName" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showFolderModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showUploadModal" class="w-full max-w-lg">
        <form wire:submit="uploadFile" class="space-y-4">
            <flux:heading level="2">{{ __('Upload file') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>
                <flux:input type="file" wire:model="upload" accept="application/pdf,.pdf,image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" />
                <flux:error name="upload" />
                <div wire:loading wire:target="upload" class="mt-1 text-sm text-zinc-500">{{ __('Uploading...') }}</div>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="fileName" />
                <flux:error name="fileName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Tags') }}</flux:label>
                <flux:input wire:model="fileTags" placeholder="{{ __('Comma, separated, tags') }}" />
                <flux:error name="fileTags" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showUploadModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="uploadFile,upload">
                    {{ __('Upload') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showEditFileModal" class="w-full max-w-lg">
        <form wire:submit="saveFile" class="space-y-4">
            <flux:heading level="2">{{ __('Edit file') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="editFileName" />
                <flux:error name="editFileName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Tags') }}</flux:label>
                <flux:input wire:model="editFileTags" placeholder="{{ __('Comma, separated, tags') }}" />
                <flux:error name="editFileTags" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showEditFileModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showPrintModal" class="w-full max-w-sm">
        <form wire:submit="queuePrint" class="space-y-4">
            <flux:heading level="2">{{ __('Print PDF') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Copies') }}</flux:label>
                <flux:input type="number" wire:model="copies" min="1" max="50" />
                <flux:error name="copies" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showPrintModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="printer">
                    {{ __('Queue print') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showViewModal" class="w-full max-w-3xl">
        @if ($this->viewingFile)
            <flux:heading level="2" class="mb-4">{{ $this->viewingFile->name }}</flux:heading>
            <div class="flex justify-center">
                <img
                    src="{{ route('admin.drive.view', $this->viewingFile) }}"
                    alt="{{ $this->viewingFile->name }}"
                    class="max-h-[70vh] max-w-full rounded-lg object-contain"
                />
            </div>
        @endif
    </flux:modal>
</div>
